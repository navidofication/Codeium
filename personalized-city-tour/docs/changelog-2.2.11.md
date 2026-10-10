# 2.2.11

- Fixed public tour-generation requests being aborted after 15 seconds on Safari/iOS and Android. Tour generation now has a 90-second client timeout because the configured AI backend can legitimately take 30–60 seconds.
- Prevented an aborted tour request from immediately retrying through the REST fallback, which could otherwise launch a duplicate AI generation while the original request was still running.
- The existing AI Audit Log continues to record the actual server-side AI request/result. The observed 33–36 second `OK` tour entries confirm that the backend was completing successfully while the previous 15-second browser timeout was killing the frontend request.
