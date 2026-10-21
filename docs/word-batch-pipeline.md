# Word batch pipeline

Every EventBridge UTC six-hour slot runs `words:generate-batch`, which idempotently creates one five-slot cohort. `words:process-batches` runs every five minutes under a Turso database lease and fills/replaces candidates, then progresses dictionary, definition, pronunciation, and .com checks. Failed work records a capped retry; a lease expiry is safely reclaimed.

Only a `ready` cohort with five current candidates is published by `words:release`. Publication uses one transaction and one shared timestamp. It creates one durable `new_word_batch` notification per recipient; the existing immediate and daily outbox paths deliver it.

Inspect `word_batches.last_error`, slot errors, and word retry columns when a cohort is delayed. Safe recovery commands are `words:generate-batch --scheduled-for=<UTC timestamp>`, `words:process-batches`, and `words:release`; none can publish a partial cohort. Metrics use `PossibleWords/WordBatches` (`BatchCreated`, `BatchReady`, `BatchPublished`, `BatchRetry`, and `BatchFanoutFailure`). Deploy migrations before the Serverless package/deployment, as in the existing deploy workflow.
