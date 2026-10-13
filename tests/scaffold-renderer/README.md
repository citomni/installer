# Scaffold renderer suite

Isolated checks for `CitOmni\Installer\Support\ScaffoldRenderer`. No Composer, no database, no environment variables.

```
php tests/scaffold-renderer/run.php
```

Expected: `18 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The renderer is pure apart from `readStub()`, whose checks use one temporary file each and remove it again.

## Checks

Rendering:

- Every canonical token is replaced, whitespace inside the braces is trimmed, and a repeated token is replaced everywhere.
- Values are inserted verbatim, backslashes and dollar signs included; an empty value removes the token; unused values are ignored.
- Rendering is a single pass: a value that looks like a token is not expanded again.
- Braces that are not placeholders are left untouched, CRLF is preserved, and no trailing newline is added.

Failures:

- An unknown placeholder fails and names the token, also when the other tokens resolve.
- Malformed tokens fail: lowercase, a leading digit, a hyphen, empty braces, a newline inside, and a dot.
- A value that is not a string fails.

`placeholdersIn()` and `readStub()`:

- `placeholdersIn()` returns sorted, unique names, is empty for a stub without tokens, and rejects a malformed token.
- `readStub()` returns the raw bytes and fails for a missing file.
