<?php

// Rather than being dependent on gaining remote SQL access to another server (and needing permissions),
// or requiring some kind of formal API, using a HEAD request can allow a simple but effective way to know whether a
// a specific page on another wiki site has been created already or not, and display this information to your users

// SPECIAL:VERSION
// Information to display on Special:Version, as it is best practice to display information of all extensions installed
//  in this manner
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__, // File name for the extension itself, required for getting the revision number from SVN - string, adding in 1.15
	'name' => 'BADI Pages Created Links', // Name of extension - string
	'description' => 'Allows display of links in toolbox to other wiki or wiki-like sites whereby links will be colored differently '.
                                    'depending on whether the page there has been created yet or not. Status determined by '.
                                    'response code or Last-Modified HTTP HEAD requests.', // Description of what the extension does - string
	'descriptionmsg' => 'badi-created-pages-desc', // Same as above but name of a message, for i18n - string, added in 1.12.0
	'version' => 0.1, // Version number of extension - number or string
	'author' => "BADI: Bahá'í/Badí Developers Institute (Brett Zamir)", // The extension author's name - string
	'url' => 'https://www.mediawiki.org/wiki/Extension:BADI_Pages_Created_Links', // URL of extension (usually instructions) - string
);

// HOOK
// Add hook for our link adder (this is the portion that allows us to hook into Mediawiki without modifying its source code)
$wgHooks['SkinTemplateToolboxEnd'][] = 'badi_addPageCreatedLinks'; // Defined below
// $wgExtensionFunctions[] = 'ef_BADIPagesCreatedLinksSetup'; // Delays execution of a named function until after setup


// Load I18N
$wgExtensionMessagesFiles['BADIPagesCreatedLinks'] = dirname( __FILE__ ) . '/BADIPagesCreatedLinks.i18n.php';


// BADI PAGE CREATED EXTENSION SETUP (do not change)

$wgBADIConfig = array();
$wgBADIConfig['sites'] = array();
$wgBADIConfig['titles'] = array();
$wgBADIConfig['external_intro'] = array();




//// START DEFAULT CONFIGURATION /////
// Although any of the following can (and probably should) be overridden in your LocalSettings.php, they should be
//   kept here in order to function as default values

// LOCALIZATION AND SITE LINKS AND TITLES

// These three arrays must have the same number of items
// For most Mediawiki sites, will need to ensure there is a slash at the end of the links
// $wgBADIConfig['titles']['default'] = array('Wikipedia');
// $wgBADIConfig['sites']['default'] = array('https://{{LANGUAGE}}.wikipedia.org/wiki/');
// $wgBADIConfig['sites_editing']['default'] = array('https://{{LANGUAGE}}.wikipedia.org/w/index.php?title=');
// $wgBADIConfig['external_intro']['default'] = ''; // See wfMsg('external-pages-w-same-title')
//
// Template variables: {{CLASS}}, {{STYLES}}, {{LOCALIZED_LINK}}, {{LOCALIZED_TITLE}}
// Fix: If necessary, the following three could be themselves localizable, though probably form would not change
// Need not be changed
$wgBADIConfig['external_site_templates'] =
    '<li><a class="{{CLASS}}" {{STYLES}} href="{{LOCALIZED_LINK}}">{{LOCALIZED_TITLE}}</a></li>'."\n";
// Template variables: {{CURRENT_PAGE_TITLE}}, {{SITE_EDITING}}
$wgBADIConfig['site_editing_templates'] = '{{SITE_EDITING}}{{CURRENT_PAGE_TITLE}}&action=edit';

// Template variables: {{SITE}}, {{CURRENT_PAGE_TITLE}}
$wgBADIConfig['site_and_title_templates'] = '{{SITE}}{{CURRENT_PAGE_TITLE}}';

// END MARKUP

//
// The user could add the above site link arrays localized into other languages here
// END LOCALIZATION


// MARKUP
// Created immediately before external sites header
// Template variables: {{LOCALIZED_INTRO}}, {{LINK_ITEMS}}
// Need not be changed
$wgBADIConfig['external_sites_templates'] = <<<HERE
    <li>{{LOCALIZED_INTRO}}
        <ul>
            {{LINK_ITEMS}}
        </ul>
    </li>
HERE;


// CSS STYLING
// Class names indicating whether a page has been created or not; relies on skin's own default pre-styled class names
// This probably will not need to be hanged
$wgBADIConfig['createdLinkClass'] = 'external';
$wgBADIConfig['uncreatedLinkClass'] = 'new';

// Leave blank unless you need specific inline styles (e.g., if you want to change the styles but don't want to
//    find or add to a stylesheet)
// Fix: make language dependent?
$wgBADIConfig['createdLinkInlineStyles'] = ''; // e.g., font-weight:bold;
$wgBADIConfig['uncreatedLinkInlineStyles'] = ''; // e.g., 'font-style:italic';
// END CSS STYLING


// USER AGENT
// Don't need to change (or even include); explicitly setting to empty string will get a HTTP 403 error from
//   Wikipedia (but not custom Mediawiki apparently unless so configured)
// $wgBADIConfig['user-agent'] = 'BADI Mediawiki page-created checker';
// $wgBADIConfig['stream_context']; // Can be used if one needs to change more than the user-agent for the HTTP HEAD request
// END USER AGENT
//// END CONFIGURATION /////



// PROBLEMS IF PUT THESE IN BODY
/*
 * Utility to determine whether a page is created already (false if not); relies on
 * built-in PHP function, get_headers(), which makes a quick HEAD request and
 * which we use to obtain its Last-Modified header; if it exists, it has been created
 * already, and if not, it has not yet been created
 * @param {String} The URL of the site to detect
 * @returns {Boolean} Whether or not the page has been created
 */
function badi_getCreatedStateForSite ($url) {
    global $wgBADIConfig;

    // Store default options to be able to return back to them later (in case MediaWiki or other extensions will rely on it)
    $defaultOpts = stream_context_get_options(stream_context_get_default());

    // Temporarily change context for the sake of get_headers() (Wikipedia, though not MediaWiki, disallows HEAD
    // requests without a user-agent specified)
    stream_context_get_default(isset($wgBADIConfig['stream_context']) ?
                $wgBADIConfig['stream_context'] : array(
                  'http' => array(
                    'user_agent' => (
                                                    isset($wgBADIConfig['user-agent']) ?
                                                        $wgBADIConfig['user-agent'] :
                                                        wfMsg('user-agent')
                                                   )
                  )
    ));
    $headers = get_headers($url, 1);

    stream_context_get_default($defaultOpts); // Set it back to original value

    $oldPage = $headers['Last-Modified'] || (strpos($headers[0], '200') !== false);
    return !!$oldPage;
}
/*
 * Our starting hook function; adds links to the Toolbox according to a user-configurable and
 * localizable list of links and titles, and styles links differently depending on whether the link has been created
 * at the target site yet or not
 * @param {Object} $this Passed by Mediawiki (required)
 */
function badi_addPageCreatedLinks ($out) {

    // GET LOCALE MESSAGES
    wfLoadExtensionMessages('BADIPagesCreatedLinks');

    global $wgRequest, $wgLanguageCode, $wgBADIConfig;

    $currentPageTitle = $wgRequest->getText('title');

    if (isset($wgBADIConfig['no_namespaces']) &&
            $wgBADIConfig['no_namespaces'] &&
            strpos($currentPageTitle, ':') !== false) {
        return false;
    }

    $badi_sites = isset($wgBADIConfig['sites'][$wgLanguageCode]) ?
                                $wgBADIConfig['sites'][$wgLanguageCode] :
                                (isset($wgBADIConfig['sites']['default']) ? // Allow user to set own default
                                    $wgBADIConfig['sites']['default'] :
                                    wfMsg('sites')); // Finally, if none specified at all, use our default

    $badi_sites_editing = isset($wgBADIConfig['sites_editing'][$wgLanguageCode]) ?
                                                    $wgBADIConfig['sites_editing'][$wgLanguageCode] :
                                                    (isset($wgBADIConfig['sites_editing']['default']) ? // Allow user to set own default
                                                            $wgBADIConfig['sites_editing']['default'] :
                                                            wfMsg('sites_editing')); // Finally, if none specified at all, use our default
    $badi_titles = isset($wgBADIConfig['titles'][$wgLanguageCode]) ?
                                    $wgBADIConfig['titles'][$wgLanguageCode] :
                                    (isset($wgBADIConfig['titles']['default']) ?  // Allow user to set own default
                                        $wgBADIConfig['titles']['default'] :
                                        wfMsg('titles')); // Finally, if none specified at all, use our default


    for ($i = 0, $link_items = '', $len = count($badi_sites); $i < $len; $i++) {
        if ($badi_sites[$i] == null) { // If the site is explicitly unspecified for the given language (or default), ignore it
            continue;
        }

        // Let user be able to dynamically determine URL (in this case one can define an array exclusively as 'default' which is our fallback)
        $site = str_replace('{{LANGUAGE}}', $wgLanguageCode, $badi_sites[$i]);
        $site_editing = str_replace('{{LANGUAGE}}', $wgLanguageCode, $badi_sites_editing[$i]);

        $siteTitle = $badi_titles[$i];
        $siteWithTitle = str_replace('{{SITE}}', $site, str_replace(
                                                                                                '{{CURRENT_PAGE_TITLE}}',
                                                                                                $currentPageTitle,
                                                                                                $wgBADIConfig['site_and_title_templates']));

        // Might allow defining inline styles for easier though less ideal configuration
        $created = badi_getCreatedStateForSite($siteWithTitle);

        $class = $created ? $wgBADIConfig['createdLinkClass'] : $wgBADIConfig['uncreatedLinkClass'];
        $styles = $created ? $wgBADIConfig['createdLinkInlineStyles'] : $wgBADIConfig['uncreatedLinkInlineStyles'];

        $siteWithTitle = $created ?
                                            $siteWithTitle :
                                            str_replace(
                                                '{{CURRENT_PAGE_TITLE}}',
                                                $currentPageTitle,
                                                str_replace('{{SITE_EDITING}}', $site_editing, $wgBADIConfig['site_editing_templates'])
                                            );

        $link_items .= str_replace(
            '{{LOCALIZED_TITLE}}',
            $siteTitle,
            str_replace(
                '{{LOCALIZED_LINK}}',
                $siteWithTitle,
                str_replace(
                    '{{CLASS}}',
                    $class,
                    str_replace(
                        '{{STYLES}}',
                        isset($styles) ? 'style="'.($styles).'"' : '',
                        $wgBADIConfig['external_site_templates']
                    )
                )
            )
        );

    }

    if ($link_items === '') {
        return false;
    }

    echo str_replace(
        '{{LINK_ITEMS}}',
        $link_items,
        str_replace(
            '{{LOCALIZED_INTRO}}',
            isset($wgBADIConfig['external_intro'][$wgLanguageCode]) ?
                $wgBADIConfig['external_intro'][$wgLanguageCode] :
                (isset($wgBADIConfig['external_intro']['default']) ?
                    $wgBADIConfig['external_intro']['default'] :
                    wfMsg('external-pages-w-same-title')),
            $wgBADIConfig['external_sites_templates']
        )
    );

    return true;
}


?>
