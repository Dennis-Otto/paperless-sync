### Bug fixes

- On Paperless-ngx 2.x, which knows a document only by the MD5 of its original, a file of the inbox, a file sent with *Send to Paperless* and a file of a rule of Flow count as imported only when the original of the document that Paperless found has the same SHA-256. Paperless Sync downloads that original to compare. Otherwise the file goes up as a file of its own, and Paperless decides: Paperless-ngx 2.x refuses it as a duplicate, and a file of the inbox then goes to the error folder with the reason of Paperless instead of leaving the inbox.
