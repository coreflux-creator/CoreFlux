# Job source ownership and review

Prepared November 14, 2026. This source change is not installed in the pilot yet. The current accepted pilot application remains `8e092ce9`. The standalone Arabella accounting release is separate.

The JobDiva job mirror and Mapping Studio job writes now share one ownership boundary. New source jobs follow observed fields; existing jobs without ownership history keep every Core field. A native edit claims the fields actually submitted, including an intentional empty value. Subsequent source changes remain available as proposals. Missing source title/status/country no longer replace the current title, reopen a closed job or truncate a country name.

The Jobs drawer shows the current and proposed values and supports a per-field decision: use the observed value once while retaining Core control, follow future updates from that exact source, or keep the current Core value. An edit in Core takes control again. All update/close/review writes require the displayed revision and a required transactional audit record. Exact decision retries return their original receipt without restoring old values. Job viewers have no edit or source-decision controls, and shared job visibility does not expose another workspace's source proposals.

Migration `modules/staffing/migrations/009_job_source_ownership.sql` adds nullable private ownership state. It does not rewrite existing jobs, their provider IDs, placements, time or financial records. Install the migration deliberately before activating the candidate code. The older payroll installer is an immutable release-specific tool and is not an installer for this migration.

Validation includes a database fixture against the canonical CI schema, actual Mapping Studio dispatch, independent MariaDB sessions racing native/source/review writes, and rendered React tests for changed-field submission, stale refusal, explicit decisions and viewer access. Full CI and hosted pilot acceptance must be recorded before this is described as deployed. The 12 job business fields remain unaccepted in the deployed field inventory until that evidence is complete.
