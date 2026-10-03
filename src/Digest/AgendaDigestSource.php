<?php

namespace Base\Agenda\Digest;

use Base\Newsletter\Digest\DigestSourceInterface;

// The class implements omnibase/newsletter's interface when the newsletter is
// installed; without it, a stand-in of the same name that nothing registers
// (config/services.php loads Digest/ only with the newsletter) - so that what
// walks every class of the bundle (omnibase's warm-up, a class map) never
// meets an interface that does not exist.
if (interface_exists(DigestSourceInterface::class)) {
    /**
     * The dates of the coming weeks in omnibase/newsletter's digest: the
     * newsletter asks for what falls between two days, the agenda answers with
     * a line per date - the day, the place, with whom. Loaded only when
     * omnibase/newsletter is installed (config/services.php).
     */
    final class AgendaDigestSource implements DigestSourceInterface
    {
        use AgendaDigest;
    }
} else {
    final class AgendaDigestSource
    {
        use AgendaDigest;
    }
}
