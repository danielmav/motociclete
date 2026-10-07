#!/usr/bin/env bash
# Rulează toate testele de newsletter (situl local trebuie să fie pornit).
PHP="${PHP:-C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe}"
cd "$(dirname "$0")/.." || exit 1
fail=0
for t in NewsletterAddressTest NewsletterRepositoryTest NewsletterSyncTest NewsletterBrevoImportTest EmailTemplateLinkTest NewsletterPublicTest NewsletterRetentionTest FitmentMatcherTest; do
    out=$("$PHP" "tests/$t.php" 2>&1); code=$?
    echo "$t: $(echo "$out" | tail -1) (exit $code)"
    [ $code -ne 0 ] && fail=1
done
exit $fail
