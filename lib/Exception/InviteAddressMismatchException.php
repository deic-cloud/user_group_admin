<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Exception;

/**
 * An e-mail invitation is being accepted by an account whose e-mail address is
 * not the one the invitation was sent to. Not an error: people have several
 * addresses ending in the same mailbox. The signup page asks for confirmation
 * and then accepts with $confirmedOtherAddress = true.
 */
class InviteAddressMismatchException extends \RuntimeException {
}
