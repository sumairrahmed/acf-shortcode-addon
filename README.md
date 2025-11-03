# 🧩 **ACF Shortcode Addon**

*A single shortcode to rule them all: dynamic ACF + SCF + WordPress field access, smart media rendering, repeaters, groups, and row-aware logic — all inside one tag.*

---

## 📘 **Overview**

The **ACF Shortcode Addon** introduces a **universal `[acf_get]` shortcode** that can read **Advanced Custom Fields (ACF)**, **Secure Custom Fields (SCF)**, and **native WordPress data** — without touching your theme’s PHP.

It behaves like a hybrid of **Pods magic-tags** and **ACF field rendering**, allowing dynamic display of any custom or core field with:

* 🧩 **Dot-notation** (`manager.post_title`, `team.0.name`)
* 🪄 **Magic tags** (`{@post_title}`)
* 🔁 **Repeaters, groups, and nested loops**
* ⚙️ **Row-aware conditionals** (`{@if field}` / `{@if field > 0}`)
* 🖼️ **Automatic media rendering** (`|img`, `|a`, `|url`)
* 🧠 **Field-label lookup** for groups
* 🪆 **Nested shortcodes** inside templates
* 🔐 **ACF + SCF + native fallback** detection

Perfect for **Elementor**, **Gutenberg**, or classic templates — it turns WordPress fields into a mini templating language.

---

## ⚙️ **Installation**

1. Create: `/wp-content/plugins/acf-shortcode-addon/`
2. Add file: `acf-shortcode-addon.php` (paste full plugin code)
3. Activate in **Dashboard → Plugins**
4. Use `[acf_get]` anywhere — in blocks, Elementor, or PHP.

---

## 🧱 **Basic Usage**

### 🔹 Self-Closing

```text
[acf_get field="fund_code"]
```

or from a taxonomy term:

```text
[acf_get field="description" ctx="term" id="term_24"]
```

or from the current user:

```text
[acf_get field="phone_number" ctx="user"]
```

### 🔹 Template Mode

```text
[acf_get]
  <h2>{@post_title}</h2>
  <p>{@post_excerpt}</p>
  <a href="{@permalink|raw}">Read More</a>
[/acf_get]
```

Templates can include loops and logic; the `field` attribute is optional.

---

## 🧠 **Attributes Reference**

| Attribute       | Type         | Description                                                         |
| --------------- | ------------ | ------------------------------------------------------------------- |
| `field`         | string       | Field name or path (`team.0.name`). Optional in template-only mode. |
| `type`          | string       | `auto`, `repeater`, `relation`, `group`. Defaults to auto-detect.   |
| `ctx`           | string       | `post`, `term`, `user`, `option`, `comment`. Default = `post`.      |
| `id`            | string / int | Object ID or prefixed (`term_3`, `user_2`). Defaults to current.    |
| `format`        | 0 / 1        | Use ACF `format_value` (1 = yes).                                   |
| `as`            | string       | Media hint (`img`, `url`, `id`, `a`, `link`, `array`).              |
| `attr`          | string       | Extra HTML attributes for media tags.                               |
| `alt` / `title` | string       | Override image / link metadata.                                     |
| `sep`           | string       | Separator for arrays (default `, `).                                |
| `limit`         | int          | Max items for arrays or repeaters.                                  |

---

## 🪄 **Magic Tags**

Magic tags live inside templates in braces `{@ ... }`.

| Example                             | Meaning                |
| ----------------------------------- | ---------------------- | 
| `{@post_title}`                     | Current post title     |
| `{@permalink}`                      | Post URL               | 
| `{@post_excerpt}`                   | Excerpt                | 
| `{@featured_image url}`             | Featured image URL  with pipe-url |
| `{@featured_image img}`             | Featured image tag  with pipe-img |
| `{@field_name}`                     | Any ACF / SCF field    |
| `{@manager.post_title}`             | Related object’s title |
| `{@i}`                              | Loop index (1-based)   |
| `{@_key}`, `{@_label}`, `{@_value}` | Inside group loops     |

Dot-notation drills through related objects or nested arrays.

---

## 🖼️ **Media Rendering**

| Field Type | Example                                                | Output                         |
| ---------- | ------------------------------------------------------ | ------------------------------ |
| Image      | `[acf_get field="hero" as="img"]`                      | `<img src="...">`              |
| File       | `[acf_get field="pdf" as="link" title="Download"]`     | `<a href="...">Download</a>`   |
| Link       | `[acf_get field="cta_link" as="a" title="Learn More"]` | `<a href="...">Learn More</a>` |
| Gallery    | `[acf_get field="gallery" as="url" limit="3" sep=","]` | Comma-separated URLs           |

Or inline pipes:

```text
{@hero|img}
{@pdf_file|a}
{@gallery|url}
```

---

## 🔁 **Repeaters & Groups**

### Simple Repeater

```text
[acf_get]
  <ul>
    {@each features}
      <li><strong>{@name}</strong> — {@description}</li>
    {@/each}
  </ul>
[/acf_get]
```

### Group Field

```text
[acf_get]
  <ul>
    {@each fund_info}
      {@if _value}
        <li><strong>{@_label}</strong>: {@_value}</li>
      {@/if}
    {@/each}
  </ul>
[/acf_get]
```

Each sub-field appears as `_key`, `_label`, `_value`.
Empty subfields are skipped automatically.

### Nested Repeaters

```text
{@each departments}
  <h3>{@name}</h3>
  {@each members}
    <p>{@name}</p>
  {@/each}
{@/each}
```

---

## 🔣 **Conditional Logic**

Pods-style conditions, fully row-aware (each repeater row evaluated independently).

```text
{@if featured}<span>⭐ Featured</span>{@/if}
```

**Comparisons**

```text
{@if price > 1000}High Value{@/if}
{@if status == "active"}Active{@/if}
{@if not archived}Visible{@/if}
```

**Nested fields**

```text
{@if link.url}Has Link{@/if}
```

**Else / Elseif**

```text
{@if category == "News"}
  News Post
{@elseif category == "Updates"}
  Update Post
{@else}
  Blog
{@/if}
```

**Contains**

```text
{@if tags contains "AI"}#ArtificialIntelligence{@/if}
```

### Row-Aware Boolean Toggle

```text
{@each fund_documents}
  {@if link > 0}
    LINK: [download link="{@fund_document_url}" name="{@fund_document_title}"]
  {@else}
    FILE: [download link="{@fund_document_file}" name="{@fund_document_title}"]
  {@/if}
{@/each}
```

The engine recognizes `1/0`, `true/false`, `on/off`, `yes/no` automatically.

---

## 🔧 **Nested Shortcodes**

Any WordPress shortcode can run inside `[acf_get]`.
The template is parsed, tokens are replaced, then `do_shortcode()` executes safely.

Example:

```text
[acf_get]
  {@each fund_documents}
    [download link="{@file|url}" name="{@title|esc}"]
  {@/each}
[/acf_get]
```

`acf_get` disables itself temporarily while evaluating to prevent recursion.

---

## 🧩 **Field Label Lookup**

Groups and repeaters expose readable labels:

```text
{@label:fund_info.some_key}
```

or via loop variables:

```text
{@_label}: {@_value}
```

Useful for generic "key : value" summaries.

---

## 🧮 **Filters & Pipes**

| Pipe                                | Purpose                    |
| ----------------------------------- | -------------------------- |
| `raw`                               | Unescaped output           |
| `esc`                               | HTML-escaped (default)     |
| `upper` / `lower`                   | Case transforms            |
| `nl2br`                             | Convert newlines to `<br>` |
| `date:F j, Y`                       | Format dates               |
| `num:2`                             | Number format              |
| `url` / `id` / `img` / `a` / `link` | Media converters           |

Example:

```text
{@post_date|date:F j, Y}
{@price|num:2}
{@hero|img}
```

---

## 🌐 **Native WordPress Fields**

Fetch core post fields directly:

| Field            | Example                                     | Output    |
| ---------------- | ------------------------------------------- | --------- |
| `post_title`     | `[acf_get field="post_title"]`              | Title     |
| `post_content`   | `[acf_get field="post_content"]`            | Content   |
| `post_excerpt`   | `[acf_get field="post_excerpt"]`            | Excerpt   |
| `featured_image` | `[acf_get field="featured_image" as="url"]` | Image URL |
| `post_date`      | `[acf_get field="post_date"]`               | Date      |
| `permalink`      | `[acf_get field="permalink"]`               | Link      |
| `author`         | `[acf_get field="post_author"]`             | Author ID |

---

## 💡 **Advanced Examples**

### 1️⃣ Blog Card

```text
[acf_get]
  <article class="blog-card">
    <a href="{@permalink|raw}">
      <img src="{@featured_image|url}" alt="{@post_title}">
      <h2>{@post_title}</h2>
    </a>
    <p>{@post_excerpt}</p>
    {@if featured}<span class="badge">Featured</span>{@/if}
  </article>
[/acf_get]
```

### 2️⃣ Repeater + Condition

```text
[acf_get]
  {@each faqs}
    <div class="faq">
      <h3>{@question}</h3>
      {@if answer}<p>{@answer|nl2br}</p>{@/if}
    </div>
  {@/each}
[/acf_get]
```

### 3️⃣ Group Summary List

```text
[acf_get]
  <ul>
    {@each fund_info}
      {@if _value}
        <li><strong>{@_label}</strong>: {@_value}</li>
      {@else}
        <li><strong>{@_label}</strong>: N/A</li>
      {@/if}
    {@/each}
  </ul>
[/acf_get]
```

### 4️⃣ Nested Shortcode Usage

```text
[acf_get]
  {@each fund_documents}
    {@if link}
      [download link="{@fund_document_url}" name="{@fund_document_title}"]
    {@else}
      [download link="{@fund_document_file}" name="{@fund_document_title}"]
    {@/if}
  {@/each}
[/acf_get]
```

---

## 🧠 **How Row Awareness Works**

Inside `{@each}`:

* Every token first checks the **current row**.
* Only if missing, it looks up in the global context.
* This prevents a global field (like `link`) from hijacking row logic.
* Empty fields in repeater/group rows are skipped by default.

---

## ⚡ **Best Practices**

✅ Escape text by default (`esc` is implicit).
✅ Use `raw` only for trusted HTML.
✅ Limit huge repeaters (`limit="10"`).
✅ Use dot-notation for nested relationships.
✅ Combine logic and shortcodes for smart output.
✅ Works perfectly inside Elementor HTML widgets.

---

## 🧩 **Developer Notes**

* PHP 7.4 +
* Compatible with **ACF Free/Pro**, **Secure Custom Fields (SCF)**, and native WP metadata.
* Safe fallback for missing fields — prints badge:
  *Field `<code>slug</code>` does not exist.*
* Regex-based lightweight template engine (no `eval`).
* Runs `do_shortcode()` internally with recursion guard.

---

## 📚 **Changelog**

**v2.2.0**

* Unified ACF / SCF / WP field engine
* Added group field loop support
* Row-aware conditional expressions
* Field label lookup (`{@_label}` / `{@label:path}`)
* Nested shortcode support
* Empty-field skipping in loops
* Safer boolean evaluation (`1/0`, `true/false`, `on/off`)
* Enhanced dot-notation for nested repeaters & relations

---

## 💬 **Elementor Example**

```text
[acf_get]
  <div class="blog-item">
    <img src="{@featured_image|url}" alt="{@post_title}">
    <h3>{@post_title}</h3>
    <p>{@post_excerpt}</p>
    <a href="{@permalink|raw}" class="btn">Read More</a>
  </div>
[/acf_get]
```

---

## 🧩 **Summary**

This addon transforms ACF + SCF + WordPress metadata into a **live templating language** — loops, logic, media, and labels all inline.

Ideal for:

* Elementor & Block Editor loops
* Custom data-driven layouts
* Relationship and repeater rendering
* Field-label summaries and conditional outputs

💬 *Think of it as “Pods Magic Tags for ACF” — lightweight, safe, and surprisingly powerful.*

---

**Author:** Sumair Ahmed
**Co-Creator:** ChatGPT (GPT-5)
**Version:** 2.2.0
**License:** GPL-2.0 or later
**Path:** `/wp-content/plugins/acf-shortcode-addon/`
