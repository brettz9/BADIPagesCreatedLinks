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
	'url' => 'http://groups.yahoo.com/group/badi', // URL of extension (usually instructions) - string
);
// Load I18N
$wgExtensionMessagesFiles['BADIPagesCreatedLinks'] = dirname( __FILE__ ) . '/BADIPagesCreatedLinks.i18n.php';


// BADI PAGE CREATED EXTENSION SETUP (do not change)

$BADIConfig = array();
$BADIConfig['sites'] = array();
$BADIConfig['titles'] = array();
$BADIConfig['external_intro'] = array();



//// START DEFAULT CONFIGURATION /////
// Although any of the following can (and probably should) be overridden in your LocalSettings.php, they should be
//   kept here in order to function as default values


// WHICH COMPONENTS TO ENABLE
$BADIConfig['Enabled_SkinTemplateToolboxEnd'] = true; // 


    


// LOCALIZATION AND SITE LINKS AND TITLES

// These three arrays must have the same number of items
// For most Mediawiki sites, will need to ensure there is a slash at the end of the links
// $BADIConfig['titles']['default'] = array('Wikipedia');
// $BADIConfig['sites']['default'] = array('http://{{LANGUAGE}}.wikipedia.org/wiki/');
// $BADIConfig['sites_editing']['default'] = array('http://{{LANGUAGE}}.wikipedia.org/w/index.php?title=');
// $BADIConfig['external_intro']['default'] = ''; // See wfMsg('external-pages-w-same-title')
//
// Template variables: {{CLASS}}, {{STYLES}}, {{LOCALIZED_LINK}}, {{LOCALIZED_TITLE}}
// Fix: If necessary, the following three could be themselves localizable, though probably form would not change
// Need not be changed
$BADIConfig['external_site_templates'] = 
    '<li><a class="{{CLASS}}" {{STYLES}} href="{{LOCALIZED_LINK}}">{{LOCALIZED_TITLE}}</a></li>'."\n";
// Template variables: {{CURRENT_PAGE_TITLE}}, {{SITE_EDITING}}
$BADIConfig['site_editing_templates'] = '{{SITE_EDITING}}{{CURRENT_PAGE_TITLE}}&action=edit';

// Template variables: {{SITE}}, {{CURRENT_PAGE_TITLE}}
$BADIConfig['site_and_title_templates'] = '{{SITE}}{{CURRENT_PAGE_TITLE}}';

// END MARKUP

//
// The user could add the above site link arrays localized into other languages here
// END LOCALIZATION


// MARKUP
// Created immediately before external sites header
// Template variables: {{LOCALIZED_INTRO}}, {{LINK_ITEMS}}
// Need not be changed
$BADIConfig['external_sites_templates'] = <<<HERE
    <li>{{LOCALIZED_INTRO}}
        <ul>
            {{LINK_ITEMS}}
        </ul>
    </li>
HERE;


// CSS STYLING
// Class names indicating whether a page has been created or not; relies on skin's own default pre-styled class names
// This probably will not need to be hanged
$BADIConfig['createdLinkClass'] = 'external';
$BADIConfig['uncreatedLinkClass'] = 'new';

// Leave blank unless you need specific inline styles (e.g., if you want to change the styles but don't want to
//    find or add to a stylesheet)
// Fix: make language dependent?
$BADIConfig['createdLinkInlineStyles'] = ''; // e.g., font-weight:bold;
$BADIConfig['uncreatedLinkInlineStyles'] = ''; // e.g., 'font-style:italic';
// END CSS STYLING


// USER AGENT
// Don't need to change (or even include); explicitly setting to empty string will get a HTTP 403 error from
//   Wikipedia (but not custom Mediawiki apparently unless so configured)
// $BADIConfig['user-agent'] = 'BADI Mediawiki page-created checker';
// $BADIConfig['stream_context']; // Can be used if one needs to change more than the user-agent for the HTTP HEAD request
// END USER AGENT
//// END CONFIGURATION /////


// Note: Whitelists are given precedence if present
// Sends info when pages are edited with new links added or old links removed (or send all links if option enabled, if never sent before
$BADIConfig['User_content_linkbacks'] = array(
    'check_preexisting_links' => true,
    'live_enabled' => true,
    'types' => array('pingback', 'trackback', 'refback', 'deleteback'),
    'whitelist' => array(),
    'blacklist' => array()
);

// Sends info when pages are created or deleted (or sends all pages if option enabled, if never sent before to that site, and if target site agrees or requests (confirm first that request is valid before notifying))
$BADIConfig['Toolbox_linkbacks'] = array( // This is for admin-specified sites; can be separate toolbox for showing incoming linkbacks
    'check_preexisting_pages' => true,
    'live_enabled' => true,
    'types' => array('pingback', 'trackback', 'refback', 'deleteback', 'catback'), // Main use here would probably be catback
    'sites' => array(),
    'site_regexps' => array()
);

$BADI = new BADI_PagesCreatedLinks($BADIConfig);



class BADI_PagesCreatedLinks {
    protected $config;
    private $inserts;
    private $deletes;
    
    public function __construct ($config) {        
        $this->config = $config;
        $this->hook_setup();
        
        if ($config['User_content_linkbacks']['check_preexisting_links']) {
    
        }
    }
    private function hook_setup () {
        global $wgHooks, $wgExtensionFunctions;
        // HOOK SETUP
        // Add hook for our link adder (this is the portion that allows us to hook into Mediawiki without modifying its source code)
        $wgHooks['SkinTemplateToolboxEnd'][] = array(&$this, 'addPageCreatedLinks'); // Defined below
        $wgHooks['LinksUpdate'][] = array(&$this, 'live_user_content_pingback'); // Defined below
        $wgHooks['ArticleSaveComplete'][] = array(&$this, 'article_save_complete'); // Defined below
        // $wgExtensionFunctions[] = 'ef_BADIPagesCreatedLinksSetup'; // Delays execution of a named function until after setup
    }
    // PROBLEMS IF PUT THESE IN BODY
    /**
     * Utility to determine whether a page is created already (false if not); relies on
     * built-in PHP function, get_headers(), which makes a quick HEAD request and
     * which we use to obtain its Last-Modified header; if it exists, it has been created
     * already, and if not, it has not yet been created
     * @param {String} The URL of the site to detect
     * @returns {Boolean} Whether or not the page has been created
     */
    public function getCreatedStateForSite ($url) {

        // Store default options to be able to return back to them later (in case MediaWiki or other extensions will rely on it)
        $defaultOpts = stream_context_get_options(stream_context_get_default());

        // Temporarily change context for the sake of get_headers() (Wikipedia, though not MediaWiki, disallows HEAD
        // requests without a user-agent specified)
        stream_context_get_default(isset($this->config['stream_context']) ? 
                    $this->config['stream_context'] : array(
                      'http' => array(
                        'user_agent' => (
                                                        isset($this->config['user-agent']) ?
                                                            $this->config['user-agent'] :
                                                            wfMsg('user-agent')
                                                       )
                      )
        ));
        $headers = get_headers($url, 1);

        stream_context_get_default($defaultOpts); // Set it back to original value

        $oldPage = $headers['Last-Modified'] || (strpos($headers[0], '200') !== false);
        return !!$oldPage;
    }
    /**
     * Our starting hook function; adds links to the Toolbox according to a user-configurable and
     * localizable list of links and titles, and styles links differently depending on whether the link has been created
     * at the target site yet or not
     * @param {Object} $this Passed by Mediawiki (required)
     */
    public function addPageCreatedLinks ($out) {
        // GET LOCALE MESSAGES
        wfLoadExtensionMessages('BADIPagesCreatedLinks');

        global $wgRequest, $wgLanguageCode;
        if (!$this->config['Enabled_SkinTemplateToolboxEnd']) { // Give chance to LocalSettings to cause exit
            return false;
        }

        $currentPageTitle = $wgRequest->getText('title');

        if (isset($this->config['no_namespaces']) &&
                $this->config['no_namespaces'] &&
                strpos($currentPageTitle, ':') !== false) {
            return false;
        }

        $badi_sites = isset($this->config['sites'][$wgLanguageCode]) ?
                                    $this->config['sites'][$wgLanguageCode] :
                                    (isset($this->config['sites']['default']) ? // Allow user to set own default
                                        $this->config['sites']['default'] :
                                        wfMsg('sites')); // Finally, if none specified at all, use our default

        $badi_sites_editing = isset($this->config['sites_editing'][$wgLanguageCode]) ?
                                                        $this->config['sites_editing'][$wgLanguageCode] :
                                                        (isset($this->config['sites_editing']['default']) ? // Allow user to set own default
                                                                $this->config['sites_editing']['default'] :
                                                                wfMsg('sites_editing')); // Finally, if none specified at all, use our default
        $badi_titles = isset($this->config['titles'][$wgLanguageCode]) ?
                                        $this->config['titles'][$wgLanguageCode] :
                                        (isset($this->config['titles']['default']) ?  // Allow user to set own default
                                            $this->config['titles']['default'] :
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
                                                                                                    $this->config['site_and_title_templates']));

            // Might allow defining inline styles for easier though less ideal configuration
            $created = $this->getCreatedStateForSite($siteWithTitle);

            $class = $created ? $this->config['createdLinkClass'] : $this->config['uncreatedLinkClass'];
            $styles = $created ? $this->config['createdLinkInlineStyles'] : $this->config['uncreatedLinkInlineStyles'];

            $siteWithTitle = $created ?
                                                $siteWithTitle :
                                                str_replace(
                                                    '{{CURRENT_PAGE_TITLE}}',
                                                    $currentPageTitle,
                                                    str_replace('{{SITE_EDITING}}', $site_editing, $this->config['site_editing_templates'])
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
                            $this->config['external_site_templates']
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
                isset($this->config['external_intro'][$wgLanguageCode]) ?
                    $this->config['external_intro'][$wgLanguageCode] :
                    (isset($this->config['external_intro']['default']) ?
                        $this->config['external_intro']['default'] :
                        wfMsg('external-pages-w-same-title')),
                $this->config['external_sites_templates']
            )
        );

        return true;
    }

    ////// FOR NEW AND OLD CONTENT (Allpages); subscriber notification, special API serving when receive polling?

    // GENERATION OF PINGBACKS (user and admin) AND DELETEBACKS?
    // 1) NEW: Parse links and submit if new, and send delete if old

    /*
    // Sends info when pages are edited with new links added or old links removed (or send all links if option enabled, if never sent before
    $this->config['User_content_linkbacks'] = array(
        "check_preexisting_links"=>true,
        "live_enabled"=>true,
        "types"=>array("pingback", "trackback", "refback", "deleteback"),
        "whitelist"=>array(),
        "blacklist"=>array()
    );

    // Hook: ArticleInsertComplete, ArticleDeleteComplete
    // Sends info when pages are created or deleted (or sends all pages if option enabled, if never sent before to that site, and if target site agrees or requests (confirm first that request is valid before notifying))
    $this->config['Toolbox_linkbacks'] = array( // This is for admin-specified sites; can be separate toolbox for showing incoming linkbacks
        "check_preexisting_pages"=>true,
        "live_enabled"=>true,
        "types"=>array("pingback", "trackback", "refback", "deleteback", "catback"), // Main use here would probably be catback
        "sites"=>array(),
        "site_regexps"=>array()
    );
    */

    /** Helper function
    * We could wait for a response and reshape external link to show error (though also needs to be available to all users)
    * @param string $inserted
    * @param string $currentPage
    * @return resource
    */
    private function process_refback ($inserted, $currentPage) {
        $errno = 0; $errstr = ''; $timeout = 15;
        $s = stream_socket_client($inserted.':80', $errno, $errstr, $timeout, // Use PHP5 method to ensure site gets visited without waiting
            STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT, 
            stream_context_create(
                array(
                    'method'=>'GET',
                    'header'=>'Referer: '.$currentPage."\r\n"
                )
            )
        );
        return $s; // We could at least confirm it at least established a socket (and as mentioned above, we could also check
        // if it gets a successful response)
    }

    // This "ArticleSaveComplete" hook runs after article save (so pingback server can find links on page)
    public function article_save_complete ($out) {
        global $wgHooks;
        $inserts = $this->inserts;
        $deletes = $this->deletes;
        $currentPage = $out->getFullURL(); // need this URL to serve as refback referrer
        
        // Unlikely would want to allow users to add catbacks
        if (in_array('pingback', $this->config['User_content_linkbacks']['types'])) {
            foreach ($inserts as $insert=>$ignore) {
                $this->config['User_content_linkbacks']['whitelist'])
                $this->config['User_content_linkbacks']['blacklist'])
                
            }
        }
        elseif (in_array('trackback', $this->config['User_content_linkbacks']['types'])) {
            foreach ($inserts as $insert=>$ignore) {
                $this->config['User_content_linkbacks']['whitelist'])
                $this->config['User_content_linkbacks']['blacklist'])
                
            }
        }
        
        // DELETIONS
        // If not enabling our special delete, we should avoid resending in case keeps being deleted and added back
        // Worth having server verify actually deleted (if trusted enough to add)
        if (in_array('deleteback', $this->config['User_content_linkbacks']['types'])) {
            foreach ($deletes as $delete=>$ignore) {
                $this->config['User_content_linkbacks']['whitelist'])
                $this->config['User_content_linkbacks']['blacklist'])
                
            }
        }
    }

    public function live_user_content_pingback ($out) {
        // SETUP
        global $wgHooks;
        $linkbackConfig = $this->config['User_content_linkbacks'];
        if (!$linkbackConfig || !$linkbackConfig['live_enabled']) {    
            return false;
        }
        $existing = $out->getExistingExternals();

        /*
        $types = array('pingback', 'trackback', 'refback');
        foreach ($types as $type) {
            if (!in_array($type, $linkbackConfig['types'])) {
                return false;
            }
            call_user_func(array(&$this, 'handler_'.$type), $out);
        }
    public function handler_pingback ($out) {
    }
    public function handler_trackback ($out) {
    }
    public function handler_refback ($out) {
    }
        */

        // INSERTS/DELETES: Store for after time page is saved (so pingback can actually find the links!; other
        //                   types might actually be able to save now and therefore quickly change link styling)
        $this->inserts = $inserts = array_diff_key( $out->mExternals, $existing ); // using keys for URL
        $this->deletes = $deletes = array_diff_key( $existing, $out->mExternals ); // using keys for URL
        
        if (in_array('refback', $linkbackConfig['types'])) {
            if (!$linkbackConfig['whitelist'] || 
                !$linkbackConfig['blacklist']) {
                return false; // Useless if no whitelist or blacklist
            }
            foreach ($inserts as $inserted => $ignore) {
                if ($linkbackConfig['whitelist']) {
                    if (in_array(
                        parse_url($inserted, PHP_URL_HOST), 
                        $linkbackConfig['whitelist']
                        )
                    ) {
                        $this->process_refback($inserted, $currentPage);
                    }
                    // If not in array, do nothing and do not check blacklist
                }
                elseif (!in_array(
                        parse_url($inserted, PHP_URL_HOST), 
                        $linkbackConfig['blacklist']
                ) {
                    $this->process_refback($inserted, $currentPage);
                }
            }        
        }
    }

    // 2) OLD: Disable NEW temporarily; Parse all latest snapshots and submit all; if new submissions since, send them & reenable (Could filter by all pages, but since might want them in the future, best to send unless advertised not to send)


    // GENERATION OF TRACKBACKS (user and admin) AND DELETEBACKS?

    // GENERATION OF REFBACKS (user and admin); ensure pages visited once

    // GENERATION OF CATBACKS (same topic) - (use hook for newly created or deleted page)
    //      (Detect <meta> indicating Edit Link when page does not exist)

}

?>