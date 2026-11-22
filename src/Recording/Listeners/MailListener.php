<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

/**
 * Listens to MessageSent. Counts mail_sent plus a per-mailable-class count, and keeps
 * sha256 hashes of lower-cased recipient addresses for Expect::mailSent()->to().
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class MailListener {}
