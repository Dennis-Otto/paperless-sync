# Check every permission that a run needs before the settings are saved, and block only what no run survives

- Status: accepted
- Date: 2026-10-13

## Context

Saving the settings tested the connection with one request, the first page of `/api/documents/`, and the page said *Connection successful*. A run reads much more: the trash, the correspondents, the document types, the storage paths and the tags for the export, and it adds documents for the inbox and reads the tasks that import them. Paperless checks a permission for each of these lists, so a service account with only *View* for Documents passed the test, and then every scheduled run failed with `Paperless could not query /api/tags/: HTTP 403`: the worst way to learn about a missing permission. The trash is narrower still: Paperless lists there only the documents that the account owns and those without an owner, unless the account is a superuser, so the trashed documents of other owners look deleted without the trash.

Paperless names the ID and the permissions of the account of a token in `/api/ui_settings/`, and whether it is a superuser, but only to an account with the permission *View* for UI Settings; its version is in the header `X-Version` of every answer to an authenticated request. Its list of documents filters by owner. A superuser sees the whole trash, but its token controls all of Paperless, so the app asks for an account that isn't one and owns the documents to mirror.

## Options

1. Keep the single test and document the permissions. Nothing to build, but the mistake still shows only in failed runs.
2. Check every list, and refuse to save on any failure. Each problem shows at once, but an administrator can't save the settings first and grant a permission afterwards, and a module that is turned on later can block every save.
3. Check every list that the enabled modules need, and the account, show the result as a checklist with what to grant for each failure, also without saving, and refuse to save only what no run can work with: Paperless that can't be reached, a token that it refuses, an account that may not view documents, and a base folder that the target user can't write. Save a permission that one module lacks with the checklist.

## Decision

Option 3. `PaperlessClientInterface::preflight()` requests the first page, with one entry, of every list that the modules need, and `/api/ui_settings/` for the ID of the account, the superuser flag and the permissions `add_document` and `view_paperlesstask` of the inbox. The line of the trash counts the documents that the account sees and another account owns, with the filters `owner__isnull` and `owner__id__none` of the document list, and passes only when there are none; the token of a superuser gets a note instead, because whoever obtains it controls all of Paperless. It reports a failure as a line of the checklist, `ConnectionCheck`, never as an exception, and a line is translated only in the controller, for the language of the administrator. `POST /settings/test` runs the checklist of the form without saving it, and `POST /settings` runs the same checks before it saves. Both send the token by the rules of a save: a stored token goes only to the URL that it was saved with, an address of the local network is reached only with the setting of the form on, and Nextcloud asks for the password of the administrator, so that a session that someone else took over can't make the server send requests into its network. A dry-run says `TRASH EMPTY` when exported documents go missing while the trash lists none.

## Consequences

A missing permission shows before the first run, with the permission to grant, and the README lists the exact permissions. Every test and every save that tests sends up to nine short requests instead of one. A save may store settings with which one module fails until a permission is granted; the checklist and the message next to the buttons say so. The checklist relies on what Paperless says of the account in `/api/ui_settings/`; when a Paperless doesn't say it, the lines of the trash and the inbox carry a note instead of a result, which names the permission *View* for UI Settings when Paperless refused the page. The README asks for that permission for the checklist alone.
