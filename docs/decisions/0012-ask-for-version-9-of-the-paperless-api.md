# Ask for version 9 of the Paperless API in every request, and need Paperless-ngx 2.16

- Status: accepted
- Date: 2026-10-14

## Context

The REST API of Paperless-ngx is versioned: a request names the version that it reads in its header `Accept`, such as `application/json; version=9`, and a request without one gets the default of the server. The app sent `Accept: application/json` alone, so the shape of every answer followed that default, which changes with Paperless. Paperless-ngx 3.0 made version 10 the default, and it lists tasks in pages, with the status in lower case and the result of a task in `result_data` instead of the text `result`. The app read only the shape of version 9, so on Paperless-ngx 3.x the `.error.txt` of every failed import said *Paperless import failed* without the reason, and a task that Paperless revoked never ended: its file waited in the inbox for good. Nothing said which versions of Paperless the app supports, and the end-to-end tests answered with a shape that no Paperless sends.

Paperless-ngx 2.16 to 2.20 serve versions 1 to 9, with 9 as their default, and 3.x serves 9 and 10, with 10 as its default. Paperless keeps an older version for at least a year after a new one, and refuses a version that it doesn't serve with `406 Not Acceptable`.

## Options

1. Keep the default of the server and read every shape. The answers change with each new default of Paperless, untested until a user meets them, and each new version adds a shape to read.
2. Ask for version 10. Paperless-ngx 2.x would refuse every request with 406, and most installations still run 2.x.
3. Ask for version 9 in every request. Paperless-ngx 2.16 and newer serve it, 3.x included, so one shape holds for all of them, until Paperless drops version 9, at the earliest a year after 3.0. Paperless-ngx 2.10 to 2.15, which don't know version 9 yet, answer 406.

## Decision

Option 3. Every request asks for version 9: the test of the connection, the lists, the downloads, the uploads and the tasks. A 406 becomes a message that names the Paperless that the app needs. The client still reads the task shape of version 10 too, should an answer come in it anyway, such as through a proxy that rewrites the header. The test of the connection reads the version of Paperless from its header `X-Version`, and the settings page shows it with the version of the API, such as *The last run reached Paperless-ngx 3.3.0 through version 9 of its API.*

## Consequences

Paperless Sync needs Paperless-ngx 2.16 or newer, released in May 2025, instead of 2.10; an older Paperless gets the message from the first run or save on. The mock of the end-to-end tests serves the versions that a Paperless of each kind serves, in the shapes of their serializers, refuses the others with 406 and counts the versions that the requests asked for, so that a request without the version fails the tests. When Paperless announces the end of version 9, a change asks for version 10 and reads its shapes, and the message of a 406 already tells the users of an older app to update it.
