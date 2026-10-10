# 2.2.13

- Removed Google Drive export work from the visitor-facing Tour Generator request path.
- Added a one-off WP-Cron queue for form/tour exports so slow Drive OAuth, token refresh and uploads no longer delay the public tour response.
- Successful tour creation now queues one combined `forms` + `tours` export job; failed AI requests queue only the saved form export.
- Preserved the existing export builder, local snapshots, Drive upload behavior, admin manual export and scheduled export system.
- Kept the 2.2.11/2.2.12 public REST timeout, AR, Suggested Stops and City Channel Intelligence changes intact.
- If WP-Cron is disabled at server level, deferred exports require a real server cron/`wp-cron.php` trigger; the tour itself remains available to the visitor.
