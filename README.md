# JSON Calendar WordPress Plugin

A lightweight WordPress plugin that fetches calendar events from a JSON endpoint and displays them with the `[json_calendar]` shortcode.

## Short feature list

- Fetches events from a remote JSON endpoint.
- Supports flat arrays, `entries` arrays, `events` arrays, and reference-keyed event objects.
- Displays upcoming events in a responsive grid.
- Provides a single featured view for the next upcoming event.
- Provides an archive view containing past events only.
- Syncs events into a dedicated `json_calendar_event` custom post type.
- Stores event data in real post meta for Elementor Dynamic Tags and ACF.
- Supports configurable result limits.
- Caches remote JSON responses for 15 minutes.
- Includes an admin settings page for the endpoint, next-event headline, and manual sync.
- Escapes and sanitizes output for safer WordPress rendering.
- Requires WordPress 5.8+ and PHP 7.4+.

## Installation

1. Copy the plugin into `wp-content/plugins/json-calendar-wp/`.
2. Activate **JSON Calendar** under **Plugins → Installed Plugins**.
3. Open **Settings → JSON Calendar**.
4. Enter the URL of the JSON endpoint.
5. Add a shortcode to a page or post.

## Configuration

Open **Settings → JSON Calendar** and configure:

### JSON endpoint URL

The endpoint must return valid JSON. The plugin accepts these common formats:

#### Flat event array

```json
[
  {
    "title": "Example event",
    "date": "2030-05-12",
    "image": "https://example.com/event-image.jpg"
  }
]
```

#### `entries` or `events` wrapper

```json
{
  "entries": [
    {
      "title": "Example event",
      "date": "2030-05-12"
    }
  ]
}
```

#### Reference-keyed object

```json
{
  "WID001": {
    "title": "Example event",
    "date": "2030-05-12",
    "image": "https://example.com/event-image.jpg"
  }
}
```

### Next-event headline

The **Next-event headline** setting controls the first line in the featured next-event view. It defaults to:

```text
Next up
```

### Manual sync

Use **Settings → JSON Calendar → Sync now** to immediately fetch the endpoint and create or update the synced event posts. The settings page also shows the last sync time and processed-event count.

## Shortcode usage

### Standard calendar view

```text
[json_calendar]
```

The standard view displays upcoming events in a responsive grid.

Optional limit:

```text
[json_calendar limit="6"]
```

### Next upcoming event

```text
[json_calendar next="true"]
```

This displays only the nearest upcoming event as a large featured image. The featured layout:

- Crops the image with `object-fit: cover`.
- Uses a responsive feature area up to approximately 1700 × 700 px on desktop.
- Uses an approximately 360 × 700 px portrait feature area on mobile.
- Places the configurable headline at the right side of the image.
- Displays the event title as the second line.
- Displays `mehr` as the third line.
- Uses white text with a subtle drop shadow.
- Does not flip on mouseover or click.
- Omits dates, times, and descriptions from the featured view.

Equivalent mode syntax:

```text
[json_calendar mode="next"]
```

### Past-event archive

```text
[json_calendar archive="true"]
```

The archive displays events that have already happened. Events are ordered with the most recently finished event first.

Optional limit:

```text
[json_calendar archive="true" limit="12"]
```

Equivalent mode syntax:

```text
[json_calendar mode="archive"]
```

### Custom endpoint per shortcode

A shortcode can override the configured endpoint:

```text
[json_calendar url="https://example.com/calendar.json"]
[json_calendar url="https://example.com/calendar.json" next="true"]
[json_calendar url="https://example.com/calendar.json" archive="true"]
```

## Supported event fields

| Field | Purpose |
| --- | --- |
| `title` | Event title. `name` and `summary` are also accepted. |
| `date` | Event start date. |
| `start_date` | Alternative start-date field. |
| `date_end` | Event end date. |
| `end_date` | Alternative end-date field. |
| `time_start` | Start time. |
| `time_end` | End time. |
| `start` | Alternative start date/time field. |
| `datetime` | Alternative date/time field. |
| `end` | Alternative end date/time field. |
| `description` | Event description. `details` and `content` are also accepted. |
| `image` | Event image URL. |
| `reference` | Event reference identifier. |

## Synced event posts and custom fields

Each synced event is stored as a real `json_calendar_event` post using the plugin's default `json-calendar-event` rewrite slug. The final permalink follows your site's permalink structure.

The plugin writes these underscore-prefixed hidden post-meta keys on every sync:

- `_json_calendar_reference`
- `_json_calendar_image_url`
- `_json_calendar_date`
- `_json_calendar_date_end`
- `_json_calendar_time_start`
- `_json_calendar_time_end`
- `_json_calendar_description`
- `_json_calendar_source_url`
- `_json_calendar_image_id` (only when an image has been sideloaded successfully)

The description is also mirrored into `post_content`, and the image is sideloaded as the featured image when possible. The underscore prefix keeps these fields hidden from the classic Custom Fields metabox, but Elementor Theme Builder Dynamic Tags → Custom Field and ACF can still read them directly by meta key from the synced posts.

## Block-theme Site Editor shortcode for event fields

If your site uses a block theme and you want to place event fields inside the Site Editor template, add a **Shortcode** block to the single template for **Calendar Events** and use:

```text
[json_calendar_meta field="date"]
```

Recommended workflow:

1. Open **Appearance → Editor → Templates**.
2. Edit the single template used for **Calendar Events**.
3. Insert a **Shortcode** block wherever an event field should appear.
4. Enter one of the supported `[json_calendar_meta]` shortcodes.
5. Save the template.

Supported fields for `[json_calendar_meta]`:

- `title`
- `date`
- `date_end`
- `time_start`
- `time_end`
- `time`
- `description`
- `image`
- `reference`

Examples:

```text
[json_calendar_meta field="date"]
[json_calendar_meta field="time"]
[json_calendar_meta field="description"]
[json_calendar_meta field="image"]
```

Optional attributes:

- `label` — adds a visible label before the field value
- `class` — adds a sanitized CSS class to the wrapper

Example with a label and class:

```text
[json_calendar_meta field="date" label="Date" class="event-date"]
```

The shortcode renders values on singular Calendar Event views, including the Site Editor's event preview context. Site Editor preview rendering requires a logged-in user who can edit templates and the previewed event post, and WordPress may sometimes provide that preview as an event post-type preview context rather than a specific preview post ID. On unrelated pages it returns an empty string.

## Date behavior

- The standard view shows upcoming events.
- The next-event view selects the nearest upcoming event.
- The archive view shows events whose end date has passed.
- If no end date exists, the event start date is used to determine whether it is in the past.
- Events are sorted chronologically in standard and next-event views.
- Archive events are sorted newest first.

## Caching

Remote JSON responses are cached with a WordPress transient for 15 minutes. This reduces repeated requests to the external endpoint while keeping the calendar reasonably current.

The cache is separated by endpoint URL and cache version.

## Empty and error states

- Administrators see a configuration message when no valid endpoint is configured.
- Visitors do not see configuration details when the endpoint is missing.
- A temporary-unavailability message is shown when the endpoint cannot be reached.
- An invalid-JSON message is shown when the endpoint does not return valid JSON.
- A dedicated empty message is shown when there are no upcoming or past events.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer
- A reachable JSON endpoint returning calendar data

## License

GPL-2.0-or-later
