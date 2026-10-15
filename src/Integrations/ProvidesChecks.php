<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * 2.22.0 A connector whose results are automatic security checks (like Huntress or security awareness training):
 * SecurityChecks adds its checks, so compliance controls, alignment standards, the health score, reports and the
 * evidence check use them like the built-in ones.
 *
 * Contract: checkPrefix() is the connector's key and "_" ("acme_"; SecurityChecks ignores a connector whose prefix
 * isn't, or that a built-in check starts with), and every key starts with it and is at most 40 characters; only keys
 * in checkLabels() are used. Indicators use the shape SecurityChecks documents: [key => ['label', 'ok' (bool),
 * 'unknown' (bool), 'text', 'suggest' (met|not_met|null)]]. A check Align can't work out (not linked, not read yet,
 * too old) is unknown, saying why; it is never a pass. Details are short text Align writes (counts, dates): no
 * people's names, email addresses or other text from the product beyond the record's own name, which the evidence
 * export redacts.
 */
interface ProvidesChecks
{
    /** The prefix of every check key ("acme_"). */
    public function checkPrefix(): string;

    /** The system's name as the evidence check shows it ("Acme"). */
    public function checkSystem(): string;

    /** Every check: key => label (shown even before the connector is set up, so standards can name them). */
    public function checkLabels(): array;

    /** One client's indicators for every check (unknown ones say why). */
    public function checkIndicators(int $clientId): array;

    /** The checks of the given clients that have results: [client id => [key => ['status' (pass|fail|unknown), 'detail']]]. */
    public function checkResults(array $clientIds): array;
}
