<?php

// Since internationalization of BADI Created Pages is all handled through configuration, giving
// full control to the user as to which content and external wiki site links to display for a given
// language, there is no need for extension localization beyond our own credits


// See https://www.mediawiki.org/wiki/Internationalisation re: plurals, etc.
$messages = array(); // Params via $1, $2, $3, etc.

$messages['en'] = array(
    // CREDITS
    // Description of what the extension does - string
    'badi-created-pages-desc' => 'Allows display of links in toolbox to other wiki or wiki-like sites whereby links will be '.
                                                            'colored differently depending on whether the page there has been created yet or not. '.
                                                            'Status determined by response code or Last-Modified headers in HTTP HEAD requests.',
    // DEFAULT TEXT AND SITES
    // These are only used if none supplied by the user
    'external-pages-w-same-title' => 'External pages with same title: ',
    'user-agent' => 'BADI Mediawiki page-created checker',
    'titles' => array('Wikipedia'),
    'sites' => array('https://{{LANGUAGE}}.wikipedia.org/wiki/'),
    'sites_editing' => array('https://{{LANGUAGE}}.wikipedia.org/w/index.php?title=')
);

?>
