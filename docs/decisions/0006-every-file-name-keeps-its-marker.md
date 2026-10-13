# Every file name keeps its marker and its extension, within the bytes of Nextcloud

- Status: accepted
- Date: 2026-10-13

## Context

The path template turns the metadata of a document into the path of its copy. The values come from Paperless and can be anything: a title with `$1`, a name in Chinese, a correspondent of 300 characters. Nextcloud rejects a name of more than 250 bytes, and the app writes a file under a temporary name 33 bytes longer first. A character of Cyrillic takes two bytes, of Chinese three and an emoji four.

The marker `[P<ID>]` in a file name ties the file to its document, so that a document is found again after a rename, and [Paperless Unified Search](https://github.com/Dennis-Otto/paperless-unified-search) links to Paperless by it. When two documents get the same path, the second one replaces the file of the first.

## Options

1. Cut every rendered name at a number of characters. Simple, but a name in another script still exceeds the bytes of Nextcloud and fails in every run, and the cut can take off the marker and the extension, so that two documents share a path.
2. Cut every rendered name at a number of bytes. The names fit, but the marker and the extension can still go.
3. Shorten only the free text of a name: the title, the correspondent, the document type, the storage path and the original file name, the longest first, until the name fits. Every file name keeps the marker and ends with the extension, and the app adds them to a template that leaves them out.

## Decision

Option 3. A folder name has at most 200 bytes and a file name at most 217, 250 less the 33 of the temporary name. The values go into the template in one pass and as plain text. The text of the template, the dates, the marker and the extension stay whole; only when they alone are too long is the name itself cut, and a file name then keeps its marker at its end.

## Consequences

Every document has a path of its own that Nextcloud accepts, in any script, and Paperless Unified Search finds every file. A custom template without `[P{{ id }}]` or `{{ extension }}` in its file name gets them, so its files move once to the new names. A long title is shortened more than before in scripts of several bytes per character. The property tests in `tests/Unit/Service/PathTemplatePropertiesTest.php` hold these rules over random templates and metadata.
