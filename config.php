<?php

// Trac authentication if required for access to the TRAC_HOST
define( 'USER', '' );
define( 'PASS', '' );

define( 'TRAC_HOST', 'glotpress.trac.wordpress.org' );
define( 'GITHUB_REPO', 'dd32/glotpress-trac2github-migration-issues' );

// PAT of a temporary user that we'll delete after the import, so that all created issues are owned by github.com/ghost.
define( 'GITHUB_TOKEN', '' );