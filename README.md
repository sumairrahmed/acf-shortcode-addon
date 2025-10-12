# 🧩 **ACF Shortcode Addon – Full Documentation**

*A single shortcode to rule them all: dynamic ACF + WordPress field access, media rendering, loops, and logic — all inside one tag.*

---

## 📘 **Overview**

The **ACF Shortcode Addon** provides a **universal `[acf_get]` shortcode** that fetches **Advanced Custom Fields (ACF)**, **Smart Custom Fields (SCF)**, and **native WordPress fields** — without any PHP templates.

It’s designed for **Elementor**, **Gutenberg**, and **classic loop templates**, making it effortless to display custom data anywhere using shortcode logic.

This plugin merges the power of **Pods magic tags** with the flexibility of **ACF**, adding:

* Smart **dot-notation** (`manager.post_title`)
* **Pods-like magic tags** (`{@post_title}`)
* **Conditionals** (`{@if field == "value"}`)
* **Loops** for repeater, gallery, and relationship fields (`{@each field}`)
* **Media rendering** (auto `<img>`, `<a>`, URLs, etc.)
* **Template-only mode** (no `field` attribute needed)

---

## ⚙️ **Installation**

1. Create a new folder inside your plugins directory:
   `/wp-content/plugins/acf-shortcode-addon/`

2. Add the file `acf-shortcode-addon.php` and paste the provided plugin code.

3. Activate **ACF Shortcode Addon** in your WordPress Dashboard → **Plugins**.

4. Use the `[acf_get]` shortcode anywhere — Elementor widgets, block editor, or classic templates.

---

## 🧱 **Basic Usage**

### 🔹 Self-Closing Shortcode

Fetch a single field’s value:

```text
[acf_get field="fund_code"]
```

Specify context:

```text
[acf_get field="description" ctx="term" id="term_24"]
```

Fetch a field from the current user:

```text
[acf_get field="phone_number" ctx="user"]
```

---

### 🔹 Enclosing Template Mode

Wrap dynamic tags with templated HTML:

```text
[acf_get field="gallery"]
  <figure><img src="{@value|url}" alt=""></figure>
[/acf_get]
```

Or omit the field attribute completely (uses current post context):

```text
[acf_get]
  <h2>{@post_title}</h2>
  <p>{@post_excerpt}</p>
  <a href="{@permalink|raw}">Read More</a>
[/acf_get]
```

---

## 🧠 **Attributes Reference**

| Attribute | Type       | Description                                                                                                                               |
| --------- | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `field`   | string     | Field name or path (`manager.post_title`, `team.0.name`). Optional in template-only mode.                                                 |
| `ctx`     | string     | Context source: `post`, `term`, `user`, `option`, `comment`. Default: `post`.                                                             |
| `id`      | string/int | Object ID or prefixed form (`term_15`, `user_2`). Defaults to current post or user.                                                       |
| `format`  | 1 or 0     | Use ACF’s `format_value` (1) or raw (0). Default: 1.                                                                                      |
| `as`      | string     | How to render media: <br>**Image** → `img`, `url`, `id`, `array`<br>**File** → `link`, `a`, `url`, `id`<br>**Link** → `a`, `url`, `title` |
| `attr`    | string     | Additional HTML attributes for `<img>` or `<a>` (e.g. `loading=lazy class=hero`).                                                         |
| `alt`     | string     | Override the image alt text.                                                                                                              |
| `title`   | string     | Override the title text for images or links.                                                                                              |
| `sep`     | string     | Separator for array output (default `, `).                                                                                                |
| `limit`   | int        | Limit number of items when field is an array.                                                                                             |

---

## 🪄 **Magic Tags**

Inside `[acf_get]...[/acf_get]` you can use **magic tags** wrapped in `{@ ... }`.
These resolve dynamically at render time.

| Example                 | Description                          |                            |
| ----------------------- | ------------------------------------ | -------------------------- |
| `{@post_title}`         | Current post title                   |                            |
| `{@permalink}`          | Post permalink                       |                            |
| `{@post_id}` / `{@ID}`  | Post ID                              |                            |
| `{@post_excerpt}`       | Post excerpt                         |                            |
| `{@featured_image       | url}`                                | Featured image URL         |
| `{@featured_image       | img}`                                | Featured image `<img>` tag |
| `{@field_name}`         | Any ACF or SCF field                 |                            |
| `{@manager.post_title}` | Related field using dot notation     |                            |
| `{@i}`                  | Current loop index (starting from 1) |                            |
| `{@value}`              | Current repeater or gallery item     |                            |

**Dot notation** works with ACF relationships, repeaters, and nested arrays:

```text
{@manager.post_title}
{@team.0.name}
```

---

## 🖼️ **Media Formatting**

ACF image, file, or link fields can be automatically formatted using the `as` attribute or a pipe (`|`) filter.

| Field Type  | Shortcode                                               | Output                         |              |
| ----------- | ------------------------------------------------------- | ------------------------------ | ------------ |
| Image (ID)  | `[acf_get field="hero" as="img"]`                       | `<img src="..." alt="...">`    |              |
| Image (URL) | `[acf_get field="hero" as="url"]`                       | URL only                       |              |
| File        | `[acf_get field="brochure" as="link" title="Download"]` | `<a href="...">Download</a>`   |              |
| ACF Link    | `[acf_get field="cta_link" as="a" title="Learn More"]`  | `<a href="...">Learn More</a>` |              |
| Gallery     | `[acf_get field="gallery" as="url" sep="                | " limit="3"]`                  | List of URLs |

**In template tags:**

```text
{@hero_image|url}
{@hero_image|img}
{@pdf_file|link}
```

---

## 🔁 **Repeater & Looping Fields**

Loop through arrays, repeaters, galleries, or relationship fields using `{@each}` blocks.

**Example:**

```text
[acf_get]
  {@each team}
    <div class="member">
      <img src="{@photo|url}" alt="{@name|esc}">
      <h3>{@name}</h3>
      <p>{@role}</p>
    </div>
  {@/each}
[/acf_get]
```

Nested loops are supported:

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

Use **Pods-style conditionals** to render content dynamically.

### Basic check

```text
{@if featured}
  <span class="badge">Featured</span>
{@/if}
```

### Comparisons

```text
{@if manager.post_title == "John"}Managed by John{@/if}
{@if post_views > 1000}Popular Post!{@/if}
```

### With else / elseif

```text
{@if category.0.name == "News"}
  <span class="badge">News</span>
{@elseif category.0.name == "Updates"}
  <span class="badge">Update</span>
{@else}
  <span class="badge">Blog</span>
{@/if}
```

### Negation

```text
{@if not comments}No comments yet{@/if}
```

### Contains

```text
{@if tags contains "AI"}#ArtificialIntelligence{@/if}
```

---

## 🧮 **Filters & Pipes**

Transform values using **pipes (`|`)** inside magic tags.

| Pipe | Action       |                               |         |    |                                        |
| ---- | ------------ | ----------------------------- | ------- | -- | -------------------------------------- |
| `    | raw`         | Output without escaping       |         |    |                                        |
| `    | esc`         | Escape for HTML (default)     |         |    |                                        |
| `    | upper`       | Uppercase text                |         |    |                                        |
| `    | lower`       | Lowercase text                |         |    |                                        |
| `    | nl2br`       | Convert newlines to `<br>`    |         |    |                                        |
| `    | date:F j, Y` | Format date string            |         |    |                                        |
| `    | num:2`       | Format number with 2 decimals |         |    |                                        |
| `    | url`, `      | id`, `                        | img`, ` | a` | Media extractors for image/file fields |

Example:

```text
{@post_date|date:F j, Y}
{@price|num:2}
{@hero|img}
{@pdf_file|a}
```

---

## 🌐 **Native WordPress Fields Supported**

You can fetch standard post fields without ACF.

| Field Name           | Example                                     | Output            |
| -------------------- | ------------------------------------------- | ----------------- |
| `post_title`         | `[acf_get field="post_title"]`              | Title text        |
| `post_content`       | `[acf_get field="post_content"]`            | Full post content |
| `post_excerpt`       | `[acf_get field="post_excerpt"]`            | Excerpt text      |
| `featured_image`     | `[acf_get field="featured_image" as="url"]` | Image URL         |
| `thumbnail_id`       | `[acf_get field="thumbnail_id"]`            | Attachment ID     |
| `slug` / `post_name` | `[acf_get field="post_name"]`               | Slug              |
| `date` / `post_date` | `[acf_get field="post_date" format="0"]`    | Raw date          |
| `modified`           | `[acf_get field="post_modified"]`           | Modified date     |
| `author`             | `[acf_get field="post_author"]`             | Author ID         |
| `permalink`          | `[acf_get field="permalink"]`               | Post link URL     |

---

## 💡 **Advanced Examples**

### 1. **Full Blog Card Template**

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

### 2. **Repeater with Nested Condition**

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

### 3. **Gallery with Limit**

```text
[acf_get field="gallery" as="url" limit="3" sep=" | "]
```

### 4. **Dynamic Call-to-Action**

```text
[acf_get field="cta_link" as="a" title="Get Started" attr="class=btn-primary"]
```

---

## 🧩 **Best Practices**

✅ **Use `|esc` or default escaping** for text to prevent HTML injection.
✅ **Use `|raw`** only for trusted HTML (like post content).
✅ **Set `format="0"`** when you want raw ACF values for programmatic use.
✅ **Leverage dot notation** for relationship/repeater subfields.
✅ **Keep loops shallow** (avoid nesting more than 2–3 levels).
✅ **Test with and without Elementor loops** — shortcode automatically adapts to context.

---

## ⚡ **Developer Notes**

* Built for PHP 7.4+
* Compatible with ACF (Free & Pro), Smart Custom Fields, and native WordPress metadata.
* Safe from fatal errors — missing fields return empty string.
* Conditionals and loops are regex-based (lightweight, no eval).
* Output automatically escapes text and attributes.

---

## 📚 **Changelog**

**v1.0.0**

* Initial release by **Sumair + ChatGPT**
* Full support for:

  * ACF + SCF field retrieval
  * Native WP post/term/user fields
  * Dot-notation
  * Magic tags
  * Conditionals (`{@if ...}`)
  * Repeaters & gallery loops (`{@each ...}`)
  * Media rendering (`as="img|url|link"`)
  * Safe escaping + automatic fallbacks

---

## 💬 **Example Use in Elementor Loop**

Place inside an Elementor *HTML Widget* within a Loop Grid:

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

## 🧠 **Summary**

This plugin turns ACF + WordPress data into a **mini templating language** — no PHP, no theme edits.
Perfect for:

* Elementor & Gutenberg loops
* Custom field-driven layouts
* Dynamic relationship & repeater rendering
* Fast prototyping of data-driven UIs

💬 *Think of it as the “Pods Magic Tags” for ACF — modern, safe, and lightning fast.*

---

**Author:** Sumair Ahmed
**Co-Creator:** ChatGPT (GPT-5)
**Version:** 1.0.0
**License:** GPLv2 or later
**Location:** `/wp-content/plugins/acf-shortcode-addon/`
