=== Board Meeting Documents ===
Contributors: graphiczen
Tags: board, meetings, agenda, minutes, pdf, shortcode
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage Board Meetings with Agenda and Minutes PDFs, plus a Document Library of grouped PDF sections (Annual Reports, Audit Reports, Budgets and more), all displayed on the frontend with shortcodes in accessible accordions.

== Description ==

Board Meeting Documents adds a **Board Meetings** post type. Each meeting has a date, a status (Normal, Cancelled, Special Meeting, Joint Meeting) and any number of Agenda and Minutes PDF documents selected from the Media Library.

Two shortcodes render the meetings on the frontend:

* `[bmd_meetings type="agenda"]` – every published meeting that has at least one Agenda PDF
* `[bmd_meetings type="minutes"]` – every published meeting that has at least one Minutes PDF

Meetings are grouped by year in an accessible accordion (button + aria-expanded + aria-controls). The latest year is expanded, older years collapsed. Each meeting links straight to its PDF in a new tab; meetings with several PDFs show one link per document.

**No page builder, no ACF, no jQuery on the frontend.** Frontend CSS/JS are only loaded on pages where the shortcode is rendered.

= Document Sections =

For PDF lists that are not tied to a meeting (Annual Reports, Audit Reports, Budget…) create **Document Sections** under Board Documents. Each section is a heading with its own collapsible list of PDFs (title + external-link icon per row). Display them with:

* `[bmd_documents]` – all published sections, ordered by their "Order" field then title
* `[bmd_documents section="annual-reports"]` – one section by slug or ID
* `[bmd_documents expand="first"]` – `all` (default), `first` or `none`

Data: post type `bmd_doc_section`, meta `_bmd_section_documents` (same `[ attachment_id, label ]` array as meeting documents).

= Where to find instructions inside WordPress =

Board Documents → Instructions shows the shortcodes, all attributes and the editing workflow. The same shortcodes are also listed in the "Shortcodes" box on every Board Meeting edit screen.

= Shortcode attributes =

* `type` – `agenda` (default) or `minutes`
* `year` – limit to one year, e.g. `year="2026"`
* `order` – `desc` (default, newest first) or `asc`
* `title` – optional heading printed above the accordion, e.g. `title="Agendas"`
* `expand` – `latest` (default), `all` or `none`

= Data model (for migrations) =

Post type: `bmd_meeting` (was `board_meeting` before 1.1.0; posts are migrated automatically)

* `_bmd_meeting_date` – string `YYYY-MM-DD`
* `_bmd_meeting_status` – `normal` | `cancelled` | `special` | `joint`
* `_bmd_agenda_documents` – array of `[ 'attachment_id' => int, 'label' => string ]`
* `_bmd_minutes_documents` – array of `[ 'attachment_id' => int, 'label' => string ]`

When a meeting has no documents of a type, that meta key is deleted (not stored as an empty array). Migration scripts should do the same, or call `BMD_Helpers::sanitize_documents()` and only save non-empty results.

= Filters =

* `bmd_date_format` – PHP date format, default `F j, Y`
* `bmd_status_suffixes` – text appended to titles per status
* `bmd_meeting_title` – final frontend title string
* `bmd_query_args` – WP_Query args used by the shortcode
* `bmd_year_heading_tag` – heading tag wrapping each year button, default `h3`
* `bmd_capability_type` – capability type of the post type, default `post`

== Installation ==

1. Upload the `board-meeting-documents` folder to `/wp-content/plugins/`, or upload the ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Create a page called "Agendas" containing `[bmd_meetings type="agenda"]`.
4. Create a page called "Minutes" containing `[bmd_meetings type="minutes"]`.
5. Go to Board Documents → Meetings (Agendas & Minutes) → Add New, enter the meeting date, choose a status, add PDFs, and publish.

== Frequently Asked Questions ==

= Do I need to edit the Agenda/Minutes pages after adding a meeting? =

No. The shortcodes query published meetings every time the page renders.

= Can I use the same PDF as both an Agenda and a Minutes document? =

Yes. Documents reference Media Library attachment IDs; nothing is copied or duplicated.

= What happens if I publish without a meeting date? =

The meeting is saved as a draft and an admin notice explains why. The date is required.

= Does uninstalling delete my meetings? =

No. Meetings and their meta are kept so nothing is lost by accident. Delete the Board Meetings manually if you want them gone.

== Changelog ==

= 1.1.0 =
* Post type slug renamed to `bmd_meeting` (was `board_meeting`) to avoid collisions with other board-meeting plugins; existing meetings are migrated automatically on first load.
* Shortcodes renamed to `[bmd_meetings]` and `[bmd_documents]`; the old `[board_meetings]` / `[board_documents]` tags keep working as aliases unless another plugin claims them.
* Admin menu renamed to "Board Documents".
* Added Document Sections (Annual Reports, Audit Reports, Budget…) with `[bmd_documents]`.
* Added Duplicate row action, row reordering, Instructions page.

= 1.0.0 =
* Initial release.
