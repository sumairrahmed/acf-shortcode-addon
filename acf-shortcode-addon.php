<?php
/**
 * Plugin Name: ACF Shortcode Addon
 * Description: One powerful shortcode for ACF/SCF fields with Pods-like niceties. Supports dot-notation, contexts (post/term/user/option/comment), media formatting, and enclosing template with magic tags.
 * Version: 1.0.0
 * Author: Sumair + ChatGPT
 */

if (!defined('ABSPATH')) exit;

class ACF_Shortcode_Addon {
  public function __construct() {
    add_shortcode('acf_get', [$this, 'shortcode']);
  }

  /* -------------------- Public: Shortcode -------------------- */
  public function shortcode($atts, $content = null) {
    $a = shortcode_atts([
      // WHAT
      'field'   => '',      // optional; supports dot: manager.post_title
      // WHERE
      'ctx'     => 'post',  // post|term|user|option|comment
      'id'      => '',      // numeric id or prefixed (term_15,user_2,comment_9)
      // HOW
      'format'  => '1',     // 1 = ACF format_value, 0 = raw
      'as'      => '',      // image: img|url|id|array; file: link|a|url|id|array; link: a|url|title
      'attr'    => '',      // HTML attrs for <img>/<a>, e.g. class="x" rel="nofollow" loading="lazy"
      'alt'     => '',      // override alt for <img>
      'title'   => '',      // override title for <a>/<img>
      // LIST
      'sep'     => ', ',    // joiner for arrays
      'limit'   => '',      // limit items for arrays
    ], $atts, 'acf_get');

    // Template-only mode allowed (Pods-like). If no field and no template → nothing to do.
    if (!$a['field'] && $content === null) return '';

    $ctxId  = $this->resolve_ctx_id($a['ctx'], $a['id']);
    $format = ($a['format'] === '1');
    $value  = $a['field'] ? $this->resolve_path($a['field'], $ctxId, $format) : null;

    // Enclosing content => template engine
    if ($content !== null) {
      $content = trim($content);
      // First loops, then conditionals (allows nesting)
      $content = $this->process_each($content, $ctxId);
      $content = $this->process_conditionals($content, $ctxId);

      if (is_array($value)) {
        $items = ($a['limit'] !== '') ? array_slice($value, 0, (int)$a['limit']) : $value;
        $out = [];
        foreach ($items as $i => $row) {
          $out[] = $this->render_template($content, $row, $ctxId, $i + 1);
        }
        return implode('', $out);
      }
      // Scalar/object or no field → render once against current context
      return $this->render_template($content, $value, $ctxId, 1);
    }

    // Self-closing behavior
    return $this->render_value($value, $a);
  }

  /* -------------------- Core resolution -------------------- */
  private function resolve_ctx_id($ctx, $id) {
    if ($id === '') {
      switch ($ctx) {
        case 'option':  return 'option';
        case 'term':    return (function_exists('get_queried_object') && ($t = get_queried_object()) && isset($t->term_id)) ? ('term_' . $t->term_id) : '';
        case 'user':    return is_user_logged_in() ? ('user_' . get_current_user_id()) : '';
        case 'comment': return '';
        case 'post':
        default:        return get_the_ID();
      }
    }
    // Prefixed ids pass-through
    if (is_string($id) && ($this->starts_with($id,'term_') || $this->starts_with($id,'user_') || $this->starts_with($id,'comment_'))) {
      return $id;
    }
    if ($ctx === 'term')    return 'term_'    . (int)$id;
    if ($ctx === 'user')    return 'user_'    . (int)$id;
    if ($ctx === 'comment') return 'comment_' . (int)$id;
    return (int)$id; // post
  }

  private function resolve_path($path, $ctxId, $format) {
    $path  = trim($path);
    $parts = array_filter(array_map('trim', explode('.', $path)));
    $base  = $this->get_field_any(array_shift($parts), $ctxId, $format);
    foreach ($parts as $seg) {
      $base = $this->segment($base, $seg, $format);
    }
    return $base;
  }

  private function segment($value, $seg, $format) {
    if ($value instanceof WP_Post) {
      if ($seg === 'permalink') return get_permalink($value);
      if (isset($value->$seg))  return $value->$seg;
      return $this->get_field_any($seg, $value->ID, $format);
    }
    if ($value instanceof WP_Term) {
      if ($seg === 'permalink') return get_term_link($value);
      if (isset($value->$seg))  return $value->$seg;
      return $this->get_field_any($seg, 'term_' . $value->term_id, $format);
    }
    if (is_array($value)) {
      $mapped = [];
      foreach ($value as $item) { $mapped[] = $this->segment($item, $seg, $format); }
      return $mapped;
    }
    return null;
  }

  private function get_field_any($name, $ctxId, $format) {
    // Normalize common aliases for easier usage
    $alias = [
      'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt',
      'slug' => 'post_name', 'date' => 'post_date', 'modified' => 'post_modified', 'author' => 'post_author',
      'type' => 'post_type', 'status' => 'post_status',
    ];
    $lookup = $alias[$name] ?? $name;

    // ACF first
    if (function_exists('get_field')) {
      $acf_val = get_field($lookup, $ctxId, $format);
      if ($acf_val !== null && $acf_val !== false) return $acf_val;
    }
    // SCF fallback
    if (function_exists('SCF::get')) {
      try { $v = SCF::get($lookup, is_numeric($ctxId)? (int)$ctxId : $ctxId); if ($v !== null) return $v; } catch (\Throwable $e) {}
    }
    if (function_exists('scf_get')) {
      try { $v = scf_get($lookup, is_numeric($ctxId)? (int)$ctxId : $ctxId); if ($v !== null) return $v; } catch (\Throwable $e) {}
    }

    // Native WordPress post fields
    $pid = is_numeric($ctxId) ? (int)$ctxId : (int)preg_replace('/\D/', '', (string)$ctxId);
    if ($pid > 0) {
      $post = get_post($pid);
      if ($post) {
        if ($lookup === 'permalink')                             return get_permalink($pid);
        if ($lookup === 'thumbnail_id' || $lookup === '_thumbnail_id') return get_post_thumbnail_id($pid) ?: null;
        if (isset($post->$lookup))                               return $post->$lookup;

        // Featured image rich object
        if (in_array($lookup, ['featured_image','post_thumbnail','thumbnail'], true)) {
          $att = get_post_thumbnail_id($pid);
          if ($att) {
            if ($format) {
              return [
                'ID'  => $att,
                'id'  => $att,
                'url' => wp_get_attachment_image_url($att, 'full'),
                'alt' => (string)get_post_meta($att, '_wp_attachment_image_alt', true),
              ];
            }
            return $att; // raw id
          }
          return null;
        }
      }
    }

    // Native term fields
    if (is_string($ctxId) && $this->starts_with($ctxId, 'term_')) {
      $term_id = (int)substr($ctxId, 5);
      $term = get_term($term_id);
      if ($term && !is_wp_error($term)) {
        if ($lookup === 'permalink') return get_term_link($term);
        if (isset($term->$lookup))   return $term->$lookup;
      }
    }

    // Native user fields
    if (is_string($ctxId) && $this->starts_with($ctxId, 'user_')) {
      $user_id = (int)substr($ctxId, 5);
      $u = get_user_by('id', $user_id);
      if ($u) {
        if ($lookup === 'name')        return $u->display_name;
        if ($lookup === 'email')       return $u->user_email;
        if ($lookup === 'login')       return $u->user_login;
        if (isset($u->$lookup))        return $u->$lookup;
      }
    }

    return null;
  }

  /* -------------------- Rendering -------------------- */
  private function render_value($value, $a) {
    if (is_array($value)) {
      $items = ($a['limit'] !== '') ? array_slice($value, 0, (int)$a['limit']) : $value;
      if ($a['as']) {
        $rendered = array_map(fn($v) => $this->render_media($v, $a), $items);
        return implode($a['sep'], array_filter($rendered, fn($x) => $x !== ''));
      }
      // Auto-coerce arrays (media-like) to URL when possible
      $coerced = array_map([$this, 'stringify_basic'], $items);
      return esc_html(implode($a['sep'], $coerced));
    }

    if ($a['as']) {
      $one = $this->render_media($value, $a);
      if ($one !== '') return $one;
    }
    return esc_html($this->stringify_basic($value));
  }

  private function render_media($v, $a) {
    $as   = strtolower(trim($a['as']));
    $attr = $a['attr'] ? ' ' . trim($a['attr']) : '';

    // Image (ID | array | URL)
    if (in_array($as, ['img','url','id','array'], true)) {
      $img = $this->normalize_image($v);
      if (!$img) return '';
      if ($as === 'array') return esc_html(json_encode($img));
      if ($as === 'id')    return esc_html((string)$img['id']);
      if ($as === 'url')   return esc_html($img['url']);
      // img
      $alt   = esc_attr(($a['alt'] !== '') ? $a['alt'] : $img['alt']);
      $title = ($a['title'] !== '') ? ' title="' . esc_attr($a['title']) . '"' : '';
      return '<img src="' . esc_url($img['url']) . '" alt="' . $alt . '"' . $title . $attr . ' />';
    }

    // File (ID | array | URL)
    if (in_array($as, ['link','a','url','id'], true)) {
      $file = $this->normalize_file($v);
      if (!$file) return '';
      if ($as === 'id')  return esc_html((string)$file['id']);
      if ($as === 'url') return esc_html($file['url']);
      $label = ($a['title'] !== '') ? esc_html($a['title']) : esc_html($file['title'] ?: basename($file['url']));
      return '<a href="' . esc_url($file['url']) . '"' . $attr . '>' . $label . '</a>';
    }

    // Link field
    if (in_array($as, ['a','url','title'], true)) {
      $link = $this->normalize_link($v);
      if (!$link) return '';
      if ($as === 'url')   return esc_html($link['url']);
      if ($as === 'title') return esc_html($link['title']);
      $target = $link['target'] ? ' target="' . esc_attr($link['target']) . '"' : '';
      $title  = ($a['title'] !== '') ? ' title="' . esc_attr($a['title']) . '"' : '';
      return '<a href="' . esc_url($link['url']) . '"' . $target . $title . $attr . '>' . esc_html($link['title']) . '</a>';
    }

    return '';
  }

  private function normalize_image($v) {
    if (is_numeric($v)) {
      $src = wp_get_attachment_image_url((int)$v, 'full');
      if (!$src) return null;
      $alt = get_post_meta((int)$v, '_wp_attachment_image_alt', true);
      return ['id' => (int)$v, 'url' => $src, 'alt' => (string)$alt];
    }
    if (is_array($v)) {
      $url = $v['url'] ?? '';
      $id  = isset($v['ID']) ? (int)$v['ID'] : (isset($v['id']) ? (int)$v['id'] : 0);
      $alt = $v['alt'] ?? ($id ? get_post_meta($id, '_wp_attachment_image_alt', true) : '');
      if ($url) return ['id' => $id, 'url' => $url, 'alt' => (string)$alt];
    }
    if (is_string($v) && filter_var($v, FILTER_VALIDATE_URL)) return ['id' => 0, 'url' => $v, 'alt' => ''];
    return null;
  }

  private function normalize_file($v) {
    if (is_numeric($v)) {
      $url = wp_get_attachment_url((int)$v);
      if (!$url) return null;
      $title = get_the_title((int)$v);
      return ['id' => (int)$v, 'url' => $url, 'title' => $title];
    }
    if (is_array($v)) {
      $url = $v['url'] ?? '';
      $id  = (int)($v['ID'] ?? $v['id'] ?? 0);
      $title = $v['title'] ?? ($id ? get_the_title($id) : '');
      if ($url) return ['id' => $id, 'url' => $url, 'title' => $title];
    }
    if (is_string($v) && filter_var($v, FILTER_VALIDATE_URL)) return ['id' => 0, 'url' => $v, 'title' => basename($v)];
    return null;
  }

  private function normalize_link($v) {
    if (is_array($v)) {
      return [
        'url'    => (string)($v['url'] ?? ''),
        'title'  => (string)($v['title'] ?? ''),
        'target' => (string)($v['target'] ?? ''),
      ];
    }
    if (is_string($v) && filter_var($v, FILTER_VALIDATE_URL)) return ['url' => $v, 'title' => $v, 'target' => ''];
    return null;
  }

  /* -------------------- Template engine -------------------- */
  private function render_template($tpl, $value, $ctxId, $index) {
    $bag = $this->build_bag($value, $ctxId, $index);
    // Enable nested each/if inside this block too
    $tpl = $this->process_each($tpl, $ctxId);
    $tpl = $this->process_conditionals($tpl, $ctxId);

    return preg_replace_callback('/\{@\s*([a-zA-Z0-9_\.\-]+)\s*(\|[^}]+)?}/', function($m) use ($bag, $ctxId) {
      $token = str_replace('-', '_', trim($m[1])); // hyphens normalize
      $pipes = isset($m[2]) ? ltrim($m[2], '|') : '';
      if ($token === 'post_id') $token = 'ID'; // alias

      $val = array_key_exists($token, $bag) ? $bag[$token] : $this->resolve_path($token, $ctxId, true);
      return $this->apply_pipes($val, $pipes);
    }, $tpl);
  }

  private function build_bag($value, $ctxId, $index) {
    $bag = [
      'ID'         => is_numeric($ctxId) ? (int)$ctxId : '',
      'post_id'    => is_numeric($ctxId) ? (int)$ctxId : '', // alias
      'post_title' => is_numeric($ctxId) ? get_the_title((int)$ctxId) : '',
      'permalink'  => is_numeric($ctxId) ? get_permalink((int)$ctxId) : '',
      'i'          => (int)$index,
      'value'      => $value,
    ];

    // Featured image quick helpers
    if (is_numeric($ctxId)) {
      $thumb = get_post_thumbnail_id((int)$ctxId);
      if ($thumb) {
        $bag['thumbnail_id']       = $thumb;
        $bag['featured_image_url'] = wp_get_attachment_image_url($thumb, 'full');
      }
    }

    if (is_array($value)) {
      foreach ($value as $k => $v) {
        if (is_scalar($v)) $bag[$k] = $v;
      }
    }
    if ($value instanceof WP_Post) { $bag += ['post_title' => $value->post_title, 'ID' => $value->ID, 'post_id' => $value->ID, 'permalink' => get_permalink($value)]; }
    if ($value instanceof WP_Term) { $bag += ['term_id' => $value->term_id, 'name' => $value->name, 'permalink' => get_term_link($value)]; }
    return $bag;
  }

  /* -------------------- Loops & Conditionals -------------------- */
  private function process_each($tpl, $ctxId) {
    // {@each field.path} ... {@/each}
    $pattern = '/\{@each\s+([a-zA-Z0-9_\.\-]+)\s*\}([\s\S]*?)\{@\/each\}/';
    $max = 20; // prevent runaway
    while ($max-- > 0 && preg_match($pattern, $tpl, $m)) {
      $full  = $m[0];
      $field = str_replace('-', '_', trim($m[1]));
      $inner = $m[2];

      $arr = $this->resolve_path($field, $ctxId, true);
      if (!is_array($arr) || empty($arr)) { $tpl = str_replace($full, '', $tpl); continue; }

      $out = [];
      $i = 1;
      foreach ($arr as $row) {
        $out[] = $this->render_template($inner, $row, $ctxId, $i++);
      }
      $tpl = str_replace($full, implode('', $out), $tpl);
    }
    return $tpl;
  }

  private function process_conditionals($tpl, $ctxId) {
    // {@if expr} ... {@elseif expr} ... {@else} ... {@/if}
    $pattern = '/\{@if\s+([^}]+)\}([\s\S]*?)\{@\/if\}/';
    $max = 20;
    while ($max-- > 0 && preg_match($pattern, $tpl, $m)) {
      $full  = $m[0];
      $expr1 = trim($m[1]);
      $body  = $m[2];

      // Split by elseif / else while keeping conditions
      $parts = preg_split('/\{\@elseif\s+([^}]+)\}|\{\@else\}/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
      $blocks = [];
      $conds  = [];
      for ($i = 0; $i < count($parts); $i++) {
        if ($i === 0) { $blocks[] = $parts[$i]; continue; }
        if ($i % 2 === 1) { $conds[] = trim($parts[$i]); }
        else { $blocks[] = $parts[$i]; }
      }

      $render = '';
      if ($this->eval_expr($expr1, $ctxId)) {
        $render = $blocks[0];
      } else {
        $matched = false; $bi = 1; $ci = 0;
        while ($ci < count($conds)) {
          if ($this->eval_expr($conds[$ci], $ctxId)) { $render = $blocks[$bi]; $matched = true; break; }
          $ci++; $bi++;
        }
        if (!$matched) { $render = end($blocks); }
      }
      $tpl = str_replace($full, $render, $tpl);
    }
    return $tpl;
  }

  private function eval_expr($expr, $ctxId) {
    $expr = trim($expr);

    // not FIELD
    if (preg_match('/^not\s+([a-zA-Z0-9_\.\-]+)$/i', $expr, $m)) {
      $v = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true);
      return !$this->truthy($v);
    }

    // FIELD (truthy)
    if (preg_match('/^([a-zA-Z0-9_\.\-]+)$/', $expr, $m)) {
      $v = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true);
      return $this->truthy($v);
    }

    // FIELD op VALUE
    if (preg_match('/^([a-zA-Z0-9_\.\-]+)\s*(==|!=|>=|<=|>|<|contains|!contains)\s*(.+)$/i', $expr, $m)) {
      $left  = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true);
      $op    = strtolower($m[2]);
      $right = $this->parse_literal(trim($m[3]));

      // Right side as field reference: {field.path}
      if (is_string($right) && preg_match('/^\{(.+)\}$/', $right, $mm)) {
        $right = $this->resolve_path(str_replace('-', '_', trim($mm[1])), $ctxId, true);
      }

      if ($this->looks_numeric($left) && $this->looks_numeric($right)) {
        $left = (float)$left; $right = (float)$right;
      } else {
        $left  = is_array($left)  ? implode(',', array_map([$this,'stringify_basic'], $left))   : (string)$left;
        $right = is_array($right) ? implode(',', array_map([$this,'stringify_basic'], $right)) : (string)$right;
      }

      switch ($op) {
        case '==': return $left == $right;
        case '!=': return $left != $right;
        case '>':  return $left >  $right;
        case '<':  return $left <  $right;
        case '>=': return $left >= $right;
        case '<=': return $left <= $right;
        case 'contains':  return is_string($left) ? (strpos($left, (string)$right) !== false) : (is_array($left) && in_array($right, (array)$left));
        case '!contains': return is_string($left) ? (strpos($left, (string)$right) === false) : (is_array($left) && !in_array($right, (array)$left));
      }
    }

    return false;
  }

  private function parse_literal($s) {
    $l = strlen($s);
    if ($l >= 2) {
      $first = $s[0]; $last = $s[$l-1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        return substr($s,1,-1);
      }
    }
    if (preg_match('/^\{[^}]+\}$/', $s)) return $s; // defer to field resolution
    $lc = strtolower($s);
    if ($lc === 'null')  return null;
    if ($lc === 'true')  return true;
    if ($lc === 'false') return false;
    if (is_numeric($s))  return $s + 0;
    return $s;
  }

  /* -------------------- Pipes, stringify, helpers -------------------- */
  private function apply_pipes($val, $pipes) {
    if ($pipes === '') return esc_html($this->stringify_basic($val));
    $parts = array_map('trim', explode('|', $pipes));
    $out = $val;

    foreach ($parts as $pipe) {
      // Media extractors / renderers in templates
      if ($pipe === 'url') {
        if (is_array($out) && !empty($out['url'])) { $out = (string)$out['url']; continue; }
        if (is_numeric($out)) { $u = wp_get_attachment_url((int)$out); $out = $u ?: ''; continue; }
        $out = (string)$out; continue;
      }
      if ($pipe === 'id') {
        if (is_array($out)) { $out = (string)($out['ID'] ?? $out['id'] ?? ''); continue; }
        $out = (string)$out; continue;
      }
      if ($pipe === 'img') {
        $img = $this->normalize_image($out);
        if (!$img) { $out = ''; continue; }
        $out = '<img src="'.esc_url($img['url']).'" alt="'.esc_attr($img['alt']).'" />';
        continue;
      }
      if ($pipe === 'a' || $pipe === 'link') {
        $file = $this->normalize_file($out);
        if (!$file) { $out = ''; continue; }
        $label = esc_html($file['title'] ?: basename($file['url']));
        $out = '<a href="'.esc_url($file['url']).'">'.$label.'</a>';
        continue;
      }

      // Basics
      if ($pipe === 'raw')   { $out = $this->stringify_basic($out); continue; }
      if ($pipe === 'esc')   { $out = esc_html($this->stringify_basic($out)); continue; }
      if ($pipe === 'upper') { $out = strtoupper($this->stringify_basic($out)); continue; }
      if ($pipe === 'lower') { $out = strtolower($this->stringify_basic($out)); continue; }
      if ($pipe === 'nl2br'){ $out = nl2br(esc_html($this->stringify_basic($out))); continue; }

      if ($this->starts_with($pipe, 'date:')) {
        $fmt = substr($pipe, 5);
        $ts  = is_numeric($out) ? (int)$out : strtotime((string)$out);
        $out = esc_html($ts ? date($fmt, $ts) : '');
        continue;
      }
      if ($this->starts_with($pipe, 'num:')) {
        $spec = explode(':', $pipe, 2)[1] ?? '';
        list($dec, $dp, $th) = array_pad(explode(',', $spec), 3, '');
        $out = is_numeric($out) ? number_format((float)$out, (int)($dec !== '' ? $dec : 0), $dp ?: '.', $th ?: ',') : '';
        $out = esc_html($out);
        continue;
      }
    }
    return $out;
  }

  private function stringify_basic($v) {
    if ($v instanceof WP_Post) return get_the_title($v);
    if ($v instanceof WP_Term) return $v->name;
    if (is_array($v)) {
      if (isset($v['url']) && is_string($v['url'])) return (string)$v['url'];
      if (isset($v['guid']) && is_string($v['guid'])) return (string)$v['guid'];
      return implode(', ', array_map(function($x){ return is_scalar($x) ? (string)$x : ''; }, $v));
    }
    if (is_bool($v)) return $v ? '1' : '';
    return is_scalar($v) ? (string)$v : '';
  }

  private function truthy($v) {
    return !($v === null || $v === false || $v === '' || (is_array($v) && count($v) === 0));
  }

  private function looks_numeric($v) {
    return is_numeric($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v));
  }

  private function starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
  }
}

new ACF_Shortcode_Addon();
