# Util suite

Isolated checks for the pure utilities `CitOmni\Installer\Util\PhpSourceComparator` and `CitOmni\Installer\Util\UnifiedDiff`. No files, no processes, no environment variables.

```
php tests/util/run.php
```

Expected: `9 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## Checks

`PhpSourceComparator`:

- Comments, PHPDoc, a license header, and whitespace do not make PHP sources differ.
- A real code difference does, and so does comment-like text inside a string.
- `__LINE__` keeps sources line-sensitive, and `__COMPILER_HALT_OFFSET__` keeps them byte-sensitive.
- Invalid PHP fails closed with a `ParseError`.

`UnifiedDiff`:

- Identical input produces no diff.
- A difference in line endings only is spelled out.
- A changed line produces a readable unified diff with headers.
