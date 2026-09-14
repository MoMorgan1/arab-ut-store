<?php

namespace App\Enums;

/**
 * How a credential correction was authorised.
 *
 * Not the same question as who submitted it. A customer can be signed in and
 * still arrive through the link in their WhatsApp message, so inferring the
 * path from the presence of a user records the wrong fact: the correction would
 * read as an ordinary account change with no trace that a capability token was
 * what let it through. The caller states the path; the user, when there is one,
 * is recorded alongside it.
 *
 * The case values are the `secret_access_logs.purpose` strings themselves, so
 * the audit vocabulary has one definition. `Account`'s value predates this enum
 * and must not change - rows written before it exist and an auditor reads them
 * the same way.
 */
enum CredentialCorrectionSource: string
{
    case Account = 'customer_credential_correction';

    case TrackingLink = 'tracking_link_credential_correction';
}
