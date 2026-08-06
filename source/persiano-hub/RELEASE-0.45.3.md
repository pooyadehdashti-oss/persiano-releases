# Batchly 0.45.3

## AI Scan Queue reliability hotfix

This release fixes two connected problems observed while a long PDF scan was pending:

1. The scan page reloaded every 15 seconds and cleared newly selected files before they were submitted. Automatic refresh now pauses whenever an upload is selected or any scan-review field has unsaved edits.
2. WooCommerce Action Scheduler could treat the currently running PDF chunk as the unique scheduled action and refuse to create the next chunk. Chunk actions are now chained safely with a job lock and a scheduled-action marker.

The page also wakes stale jobs when opened, includes a manual Refresh jobs button, and displays the host's current upload limit.
