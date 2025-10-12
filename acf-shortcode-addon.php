<?php
/**
 * Plugin Name: ACF Shortcode Addon
 * Description: One powerful shortcode for ACF/SCF fields with Pods-like niceties. Supports dot-notation, contexts (post/term/user/option/comment), media formatting, repeater/relationship (incl. nested) handling, and enclosing template with magic tags. Includes robust SCF/RAW meta fallback for repeaters.
 * Version: 2.2.9
 * Author: Sumair + ChatGPT
 */

if (!defined('ABSPATH')) exit;

class ACF_Shortcode_Addon {
  public function __construct() {
    add_shortcode('acf_get', [$this, 'shortcode']);
  }

  /* ===================== Public: Shortcode ===================== */
  public function shortcode($atts, $content = null) {
    $a = shortcode_atts([
      // WHAT
      'field'   => '',        // field path (dot-notation), e.g. features_test.0.name
      'type'    => 'auto',    // auto|repeater|relation (forces handler for the FIRST segment)
      // WHERE
      'ctx'     => 'post',    // post|term|user|option|comment
      'id'      => '',        // numeric id or prefixed (term_15,user_2,comment_9)
      // HOW
      'format'  => '1',       // 1 = format_value (ACF) where applicable; 0 = raw
      'as'      => '',        // image: img|url|id|array; file: link|a|url|id|array; link: a|url|title
      'attr'    => '',        // HTML attrs for <img>/<a>, e.g. class="x" rel="nofollow" loading="lazy"
      'alt'     => '',        // override alt for <img>
      'title'   => '',        // override title for <a>/<img>
      // LIST
      'sep'     => ', ',      // string joiner for arrays
      'limit'   => '',        // limit items for arrays
    ], $atts, 'acf_get');

    // Template-only mode allowed. If no field and no template → nothing to do.
    if ($a['field'] === '' && $content === null) return '';

    $ctxId  = $this->resolve_ctx_id($a['ctx'], $a['id']);
    $format = ($a['format'] === '1');

    // Resolve value (field may be empty in template-only mode; then $value = null)
    $value = $a['field'] !== '' ? $this->resolve_path($a['field'], $ctxId, $format, strtolower($a['type'])) : null;

    // Enclosing template → run mini-engine
    if ($content !== null) {
      $tpl = trim($content);

      // Support loops & conditionals on the full template before token expansion
      $tpl = $this->process_each($tpl, $ctxId);
      $tpl = $this->process_conditionals($tpl, $ctxId);

      if (is_array($value)) {
        $items = ($a['limit'] !== '') ? array_slice($value, 0, (int)$a['limit']) : $value;
        $out = [];
        foreach ($items as $i => $row) {
          $out[] = $this->render_template($tpl, $row, $ctxId, $i + 1);
        }
        return implode('', $out);
      }
      return $this->render_template($tpl, $value, $ctxId, 1);
    }

    // Self-closing
    return $this->render_value($value, $a);
  }

  /* ===================== Context ===================== */
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
    if (is_string($id) && ($this->starts_with($id,'term_') || $this->starts_with($id,'user_') || $this->starts_with($id,'comment_'))) return $id;
    if ($ctx === 'term')    return 'term_'    . (int)$id;
    if ($ctx === 'user')    return 'user_'    . (int)$id;
    if ($ctx === 'comment') return 'comment_' . (int)$id;
    return (int)$id;
  }

  /* ===================== Path Resolver (Repeater/Relation aware) ===================== */
  /**
   * Resolve a field path against a context.
   * - First segment may be forced via $type: auto|repeater|relation
   * - Supports nested repeaters and relationships via dot-notation and list/assoc array walking
   */
  private function resolve_path($path, $ctxId, $format, $type = 'auto'){
    $path  = trim($path);
    if ($path === '') return null;
    $parts = array_values(array_filter(array_map('trim', explode('.', $path)), fn($p)=>$p!==''));
    if (!$parts) return null;

    $first = array_shift($parts);

    // Prime FIRST segment based on 'type' or auto-detect from field object
    if ($type === 'repeater' || ($type === 'auto' && $this->is_acf_repeater($first, $ctxId))) {
      $base = $this->get_repeater_rows_any($first, $ctxId, $format);
    } elseif ($type === 'relation' || ($type === 'auto' && $this->is_acf_relation($first, $ctxId))) {
      $base = $this->get_acf_relation_items($first, $ctxId, $format);
    } else {
      $base = $this->get_field_any($first, $ctxId, $format);
    }

    // Descend remaining segments
    foreach ($parts as $seg){
      $base = $this->segment($base, $seg, $format, $ctxId);
    }
    return $base;
  }

  /* ---------- Field Type Helpers ---------- */
  private function is_acf_repeater($field, $ctxId): bool {
    if (!function_exists('get_field_object')) return false;
    $fo = get_field_object($field, $ctxId, false, false);
    return is_array($fo) && ($fo['type'] ?? '') === 'repeater';
  }

  private function is_acf_relation($field, $ctxId): bool {
    if (!function_exists('get_field_object')) return false;
    $fo = get_field_object($field, $ctxId, false, false);
    if (!is_array($fo)) return false;
    $t = $fo['type'] ?? '';
    // Treat these as "relation-like"
    return in_array($t, ['relationship','post_object','taxonomy','user','term'], true);
  }

  private function get_acf_relation_items($field, $ctxId, $format) {
    if (function_exists('get_field')) {
      $v = get_field($field, $ctxId, $format);
      if ($v === null || $v === false) return [];
      return is_array($v) ? $v : [$v];
    }
    // SCF (if relation fields returned as array)
    if (class_exists('SCF') && is_callable(['SCF','get'])) {
      try { $v = SCF::get($field, is_numeric($ctxId)? (int)$ctxId : $ctxId); if ($v !== null) return is_array($v) ? $v : [$v]; } catch (\Throwable $e) {}
    }
    if (function_exists('scf_get')) {
      try { $v = scf_get($field, is_numeric($ctxId)? (int)$ctxId : $ctxId); if ($v !== null) return is_array($v) ? $v : [$v]; } catch (\Throwable $e) {}
    }
    return [];
  }

  /** Repeater rows loader: ACF iterator → SCF → get_field → raw meta/options (fallback) */
  private function get_repeater_rows_any($field, $ctxId, $format): array {
    $is_option = ($ctxId === 'option');
    $post_id   = !$is_option ? (is_numeric($ctxId) ? (int)$ctxId : (int)preg_replace('/\D/', '', (string)$ctxId)) : 0;

    // A) ACF iterator API (docs-accurate)
    if (!$is_option && function_exists('have_rows') && function_exists('the_row') && function_exists('get_sub_field') && $this->is_acf_repeater($field, $ctxId)) {
      $rows = [];
      $fo = function_exists('get_field_object') ? get_field_object($field, $ctxId, false, false) : null;
      $subs = (is_array($fo) && isset($fo['sub_fields']) && is_array($fo['sub_fields'])) ? $fo['sub_fields'] : [];
      if (have_rows($field, $ctxId)) {
        while (have_rows($field, $ctxId)) {
          the_row();
          $row = [];
          if ($subs) {
            foreach ($subs as $sf) {
              $key = $sf['name'] ?? ($sf['key'] ?? '');
              if ($key) $row[$key] = get_sub_field($key);
            }
          }
          $rows[] = $row;
        }
        if (function_exists('reset_rows')) { @reset_rows($field, $ctxId); }
      }
      if (!empty($rows)) return $rows;
    }

    // B) SCF accessors
    try {
      if (!$is_option && class_exists('SCF') && is_callable(['SCF','get'])) {
        $v = SCF::get($field, $post_id);
        if (is_array($v) && !empty($v)) return $v;
      }
    } catch (\Throwable $e) {}
    if (!$is_option && function_exists('scf_get')) {
      try { $v = scf_get($field, $post_id); if (is_array($v) && !empty($v)) return $v; } catch (\Throwable $e) {}
    }

    // C) get_field() compatibility
    if (function_exists('get_field')) {
      $target = $is_option ? 'option' : $post_id;
      $v = get_field($field, $target, $format);
      if (is_array($v) && !empty($v)) return $v;
    }

    // D) RAW fallback (ACF-style storage)
    if ($is_option) {
      // options table
      $rows = [];
      $count = get_option($field);
      if (is_numeric($count) && (int)$count > 0) {
        $count = (int)$count;
        for ($i=0; $i<$count; $i++) { $rows[$i] = $this->probe_collect_row_from_option($field, $i); }
        return $rows;
      }
      // Heuristic (avoid scanning entire options table)
      $common = ['name','description','icon'];
      for ($i=0; $i<100; $i++) {
        $row = []; $found = false;
        foreach ($common as $k) {
          $val = get_option("{$field}_{$i}_{$k}", null);
          if ($val !== null && $val !== '') { $row[$k] = $val; $found = true; }
        }
        if ($found) { $rows[] = $row; } else break;
      }
      return $rows;
    } else {
      // post meta
      $count = get_post_meta($post_id, $field, true);
      if (is_numeric($count) && (int)$count > 0) {
        $count = (int)$count;
        $rows = [];
        for ($i=0; $i<$count; $i++) { $rows[$i] = $this->probe_collect_row_from_meta($post_id, $field, $i); }
        return $rows;
      }
      $all = get_post_meta($post_id);
      $bucket = [];
      foreach ($all as $mk => $vals) {
        if (preg_match('/^'.preg_quote($field,'/').'_(\d+)_([A-Za-z0-9_]+)$/', $mk, $m)) {
          $idx = (int)$m[1]; $sub = $m[2];
          $bucket[$idx] = $bucket[$idx] ?? [];
          $bucket[$idx][$sub] = $vals[0] ?? '';
        }
      }
      if (!empty($bucket)) {
        ksort($bucket);
        return array_values($bucket);
      }
    }

    return [];
  }

  /** helpers used by the raw fallback */
  private function probe_collect_row_from_meta($post_id, $field, $i){
    $row = [];
    foreach (['name','description','icon'] as $k) {
      $row[$k] = get_post_meta($post_id, "{$field}_{$i}_{$k}", true);
    }
    $all = get_post_meta($post_id);
    foreach ($all as $mk => $vals) {
      if (preg_match('/^'.preg_quote($field,'/').'_'.$i.'_'.'([A-Za-z0-9_]+)$/', $mk, $m)) {
        $sub = $m[1];
        if (!array_key_exists($sub, $row)) $row[$sub] = $vals[0] ?? '';
      }
    }
    return $row;
  }
  private function probe_collect_row_from_option($field, $i){
    $row = [];
    foreach (['name','description','icon'] as $k) {
      $row[$k] = get_option("{$field}_{$i}_{$k}", '');
    }
    return $row;
  }

  /* ---------- Segment walker (nested-friendly) ---------- */
  private function segment($value, $seg, $format, $ctxId){
    // WP_Post: allow native props + recurse into fields for that post
    if ($value instanceof WP_Post){
      if ($seg === 'permalink') return get_permalink($value);
      if (isset($value->$seg))  return $value->$seg;
      return $this->get_field_any($seg, $value->ID, $format);
    }

    // WP_Term
    if ($value instanceof WP_Term){
      if ($seg === 'permalink') return get_term_link($value);
      if (isset($value->$seg))  return $value->$seg;
      return $this->get_field_any($seg, 'term_'.$value->term_id, $format);
    }

    // WP_User (relation 'user' type)
    if ($value instanceof WP_User){
      if (isset($value->$seg)) return $value->$seg;
      if ($seg === 'name' || $seg === 'display_name') return $value->display_name;
      if ($seg === 'email' || $seg === 'user_email')  return $value->user_email;
      if ($seg === 'login' || $seg === 'user_login')  return $value->user_login;
      return null;
    }

    // Array (list vs assoc)
    if (is_array($value)){
      if ($this->is_list($value)) {
        if (is_numeric($seg)) {
          $i = (int)$seg;
          return array_key_exists($i, $value) ? $value[$i] : null;
        }
        $mapped = [];
        foreach ($value as $item){
          $mapped[] = $this->segment($item, $seg, $format, $ctxId);
        }
        return $mapped;
      }
      if (array_key_exists($seg, $value)) {
        return $value[$seg];
      }
      return null;
    }

    // Scalars: nothing deeper
    return null;
  }

  private function is_list(array $a): bool {
    if ($a === []) return true;
    $keys = array_keys($a);
    return $keys === range(0, count($a) - 1);
  }

  /* ===================== Field Getter (ACF → SCF → native) ===================== */
  private function get_field_any($name, $ctxId, $format){
    $alias = [
      'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt',
      'slug' => 'post_name', 'date' => 'post_date', 'modified' => 'post_modified',
      'author' => 'post_author', 'type' => 'post_type', 'status' => 'post_status',
      'permalink' => 'permalink',
    ];
    $lookup = $alias[$name] ?? $name;

    // ACF first
    if (function_exists('get_field')) {
      $acf_val = get_field($lookup, $ctxId, $format);
      if ($acf_val !== null && $acf_val !== false) return $acf_val;
    }
    // SCF fallback
    if (class_exists('SCF') && is_callable(['SCF','get'])) {
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
            return $att;
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

  /* ===================== Rendering ===================== */
  private function render_value($value, $a) {
    if (is_array($value)) {
      $items = ($a['limit'] !== '') ? array_slice($value, 0, (int)$a['limit']) : $value;
      if ($a['as']) {
        $rendered = array_map(fn($v) => $this->render_media($v, $a), $items);
        return implode($a['sep'], array_filter($rendered, fn($x) => $x !== ''));
      }
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

  /* ===================== Template Engine ===================== */
  private function render_template($tpl, $value, $ctxId, $index) {
    $bag = $this->build_bag($value, $ctxId, $index);

    // Quick {field}_count token (e.g., {@features_test_count})
    $tpl = preg_replace_callback('/\{@\s*([a-zA-Z0-9_]+)_count\s*\}/', function($mm) use ($ctxId){
      $fname = $mm[1];
      $rows = $this->is_acf_repeater($fname, $ctxId)
        ? $this->get_repeater_rows_any($fname, $ctxId, true)
        : (array) $this->resolve_path($fname, $ctxId, true, 'auto');
      return esc_html((string)count($rows));
    }, $tpl);

    // Loops and conditionals inside nested templates
    $tpl = $this->process_each($tpl, $ctxId);
    $tpl = $this->process_conditionals($tpl, $ctxId);

    // Token expansion
    return preg_replace_callback('/\{@\s*([a-zA-Z0-9_\.\-]+)\s*(\|[^}]+)?}/', function($m) use ($bag, $ctxId, $value) {
      $token = str_replace('-', '_', trim($m[1])); // normalize hyphens
      $pipes = isset($m[2]) ? ltrim($m[2], '|') : '';
      if ($token === 'post_id') $token = 'ID'; // alias

      // row-first lookup: bag → row-array/object → global path
      if (array_key_exists($token, $bag)) {
        $val = $bag[$token];
      } elseif (is_array($value) && array_key_exists($token, $value)) {
        $val = $value[$token];
      } elseif ($value instanceof WP_Post && isset($value->$token)) {
        $val = $value->$token;
      } elseif ($value instanceof WP_Term && isset($value->$token)) {
        $val = $value->$token;
      } elseif ($value instanceof WP_User && isset($value->$token)) {
        $val = $value->$token;
      } else {
        $val = $this->resolve_path($token, $ctxId, true, 'auto');
      }

      return $this->apply_pipes($val, $pipes);
    }, $tpl);
  }

  private function build_bag($value, $ctxId, $index) {
    $bag = [
      'ID'         => is_numeric($ctxId) ? (int)$ctxId : '',
      'post_id'    => is_numeric($ctxId) ? (int)$ctxId : '',
      'post_title' => is_numeric($ctxId) ? get_the_title((int)$ctxId) : '',
      'permalink'  => is_numeric($ctxId) ? get_permalink((int)$ctxId) : '',
      'i'          => (int)$index,
      'value'      => $value,
    ];

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
    if ($value instanceof WP_User) { $bag += ['user_id' => $value->ID, 'name' => $value->display_name, 'email' => $value->user_email]; }
    return $bag;
  }

  /* -------- Loops & Conditionals -------- */
  private function process_each($tpl, $ctxId){
    // {@each <path>} ... {@/each} — <path> can be nested (e.g., features_test.0.child_repeater)
    $pattern = '/\{@each\s+([a-zA-Z0-9_\.\-]+)\s*\}([\s\S]*?)\{@\/each\}/';
    $max = 30;
    while ($max-- > 0 && preg_match($pattern, $tpl, $m)){
      $full  = $m[0];
      $path  = str_replace('-', '_', trim($m[1]));
      $inner = $m[2];

      $rows = $this->resolve_path($path, $ctxId, true, 'auto');

      if (!is_array($rows) || empty($rows)) { $tpl = str_replace($full, '', $tpl); continue; }

      $out = [];
      $i = 1;
      foreach ($rows as $row){
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
      $v = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true, 'auto');
      return !$this->truthy($v);
    }

    // FIELD (truthy)
    if (preg_match('/^([a-zA-Z0-9_\.\-]+)$/', $expr, $m)) {
      $v = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true, 'auto');
      return $this->truthy($v);
    }

    // FIELD op VALUE
    if (preg_match('/^([a-zA-Z0-9_\.\-]+)\s*(==|!=|>=|<=|>|<|contains|!contains)\s*(.+)$/i', $expr, $m)) {
      $left  = $this->resolve_path(str_replace('-', '_', $m[1]), $ctxId, true, 'auto');
      $op    = strtolower($m[2]);
      $right = $this->parse_literal(trim($m[3]));

      if (is_string($right) && preg_match('/^\{(.+)\}$/', $right, $mm)) {
        $right = $this->resolve_path(str_replace('-', '_', trim($mm[1])), $ctxId, true, 'auto');
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

  /* ===================== Pipes & Utils ===================== */
  private function apply_pipes($val, $pipes) {
    if ($pipes === '') return esc_html($this->stringify_basic($val));
    $parts = array_map('trim', explode('|', $pipes));
    $out = $val;

    foreach ($parts as $pipe) {
      // Media extractors / renderers
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
    if ($v instanceof WP_User) return $v->display_name;
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

