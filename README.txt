(This extension is dedicated to our Baha'i friends in Iran who, without 
responding in kind, but on the contrary, who persevere in demonstrating their
loyalty and services to their communities and country, despite the
government-sponsored persecution so insidiously leagued against them 
(including a denial of education itself! not to mention jobs, pensions, 
and businesses and other abuses or harrassment, even of poor school children). 
No doubt Iran as a whole will return to its prior glories as soon as it 
stops its patently false propaganda, trumped up charges and imprisonments,
and becomes concerned with elevating the status of all of its citizens, 
whether women, ethnic minorities, or Baha'is (the largest independent 
religious minority in the country). Civilized countries do not come anywhere
close to doing things like denying education to its own citizens, citizens 
who are moreover loyal to its authority! Is there any excuse for such 
backwards behavior? Iran no doubt has yet greater contributions to 
make to civilization, if it will only be truly enabled to do so.)

**************************************************************************
NOTE: THE FOLLOWING FEATURES ARE ONLY BEING DESCRIBED HERE TO DOCUMENT
THE INTENDED FEATURES; THESE FEATURES HAVE NOT YET BEEN IMPLEMENTED YET!!!
**************************************************************************

The BADI Pages Created Links extension for Mediawiki allows:

1) Receipt and display of pingbacks, trackbacks, refbacks, and a new linkback we will call 
catbacks (see 3b below) with options to:
    a) decide whether these backlinks will appear inline and/or via the "What Links Here" page
    b) decide whether the display is controllable from within wiki code or only via admin specification. 
    c) decide, if control is made through admin specification, whether to show orange links, which
        indicate that the page has not yet been created, but one may visit that site (wiki or discussion) 
        to create the link where relevant.
    d) decide, if control is made through admin specification, whether to make live checks for content
        on sites which are not sending their own automated linkbacks (e.g., catbacks) via 
        Last-Modified or HTTP 200 HTTP HEAD requests. This is less efficient and can slow down 
        both your server (and/or the user client) as well as the targeted external server as it 
        requires a request for each visit (unless caching is enabled which may mean the wiki is out 
        of date in the case of since deleted pages). This can be configured to be sent via 
        JavaScript (in which case the user's machine sends the request to a cross-domain API) or by the server.
    e) restrict by domain whitelist or blacklist
    f) restrict by whether the links originate with a "nofollow" (or "rel"?) attribute or not
2) Generation of pingbacks, trackbacks and/or refbacks (the latter by visiting the site), with options to:
    a) decide whether the generation of links can be made within wiki code or only via admin specification
    b) restrict by domain whitelist or blacklist
    c) generate pingbacks, trackbacks, and refbacks to be triggered according to the preexisting links within
    the database and/or to run after each new page edit
3) Expansion of the pingback/trackback protocols, by allowing:
    a) an automatic command to indicate deletion of an entry (since the wiki inclusion of links 
    may fluctuate more than the blogs for which pingback was designed)
    b) an automatic admin-controlled command to indicate that a page is dedicated to the same 
    topic (and is not just referencing the wiki article). This is particularly suitable 
    for pinging other wikis, blogs, or discussion forums covering the same scope of topics, 
    whether for their articles or category pages (It is hoped that some discussion forums 
    (or wikis) might allow the unalterable stream of a discussion that discussion forums 
    allow with the ability for users to freely add and edit not only threads but also 
    categories and an infinite nesting of subcategories.)


To use this extension, simply put the BADIPagesCreatedLinks folder containing
our extension code inside your wiki's /extensions folder and include this 
line in your LocalSettings.php:
    require_once($IP.'/extensions/BADIPagesCreatedLinks/BADIPagesCreatedLinks.php');

After this line, you can then optionally use any of the $wgBADIConfig 
configuration properties inside of LocalSettings.php (do not edit the extension
files as full configuration should be possible inside LocalSettings.php alone,
also potentially allowing you to update to any future version of our extension 
with a minimum of hassle).


titles, sites, sites_editing:

These 3 properties are the most likely you will wish to modify (unless you only
wish to link your pages to Wikipedia).

Each of these properties is an array keyed to a language code (if you wish to
provide language-specific link information) or to the word "default" which in 
turn lead to an array of values, respectively: 
1) titles: site title
2) sites: a template for the base URL for when the page has already been 
    created. {{LANGUAGE}}, if present, will be replaced by the current 
    language code.
3) sites_editing: a template for the base URL for when the page has not yet 
    been created and will be edited. {{LANGUAGE}}, if present, will be 
    replaced by the current language code.

"default" can be used in place of a language to indicate the default values 
and patterns.

For example, the following allows us to link to Wikipedia and Bahaikipedia
while allowing a different pattern for English, since "en." is not used
in the URL:

$wgBADIConfig['titles']['default'] = array('Wikipedia', 'Bahaikipedia');
$wgBADIConfig['sites']['default'] = array('http://{{LANGUAGE}}.wikipedia.org/wiki/', 'http://{{LANGUAGE}}.bahaikipedia.org/');
$wgBADIConfig['sites_editing']['default'] = array('http://{{LANGUAGE}}.wikipedia.org/w/index.php?title=', 'http://{{LANGUAGE}}.bahaikipedia.org/index.php?title=');

$wgBADIConfig['titles']['en'] = array('Wikipedia', 'Bahaikipedia');
$wgBADIConfig['sites']['en'] = array('http://{{LANGUAGE}}.wikipedia.org/wiki/', 'http://bahaikipedia.org/');
$wgBADIConfig['sites_editing']['en'] = array('http://{{LANGUAGE}}.wikipedia.org/w/index.php?title=', 'http://bahaikipedia.org/index.php?title=');

An item in a "sites" language array can be set to NULL if a link should not be
generated for that particular language.

Although it is not necessary to change the default values, this extension 
also allows you to configure the  structure for the markup that will be 
produced.

The template for configuring the entire block that will be added 
as a whole is "external_sites_templates:

$wgBADIConfig['external_sites_templates'] = <<<HERE
    <li>{{LOCALIZED_INTRO}}
        <ul>
            {{LINK_ITEMS}}
        </ul>
    </li>
HERE;

It allows two variables, the first being "{{LOCALIZED_INTRO}}" which is 
merely the introductory text for the links and "{{LINK_ITEMS}}" which are
the individual line items containing the links.

Each individual line item also has a temploate, "external_site_templates":

    $wgBADIConfig['external_site_templates'] = 
        '<li><a class="{{CLASS}}" {{STYLES}} href="{{LOCALIZED_LINK}}">{{LOCALIZED_TITLE}}</a></li>'."\n";

The template variable "{{CLASS}}" will be determined according to whether 
the page has been created yet or not. (The default values will either be
"external" or "new"--see below.)

The template variable "{{STYLES}}" can allow inline styling information for
convenient styling of individual links, also sensitive to whether the page
has been created yet or not. 

These can be added via:
    $wgBADIConfig['createdLinkInlineStyles'] = ''; // e.g., font-weight:bold;
    $wgBADIConfig['uncreatedLinkInlineStyles'] = ''; // e.g., 'font-style:italic';

Note, however, that the {{CLASS}} variable is really a better choice
as it allows control from an external stylesheet and can take advantage 
of the already-familiar styling differences within Mediawiki
as far as external (which we interpret here as already-created) links or 
new ones (styled the same as though they were internal links, by default in
orange color).

The name of the classes that will be created (for created or as-yet-uncreated 
pages) can also be configured, though one may wish to just use the default
classes (not necessary to specify them if using the default), since these use
the already familiar Mediawiki styling:

$wgBADIConfig['createdLinkClass'] = 'external';
$wgBADIConfig['uncreatedLinkClass'] = 'new';


It is also possible, though probably not necessary for most people, to 
configure the link  structure for both already-created links and new links. 
If there is interest, we could change the code to make these differ by 
language and/or site.

The variable {{SITE}} will be replaced by the base URL for an already created
external page, while {{CURRENT_PAGE_TITLE}} will be replaced by the current
wiki page's title:

$wgBADIConfig['site_and_title_templates'] = '{{SITE}}{{CURRENT_PAGE_TITLE}}';

Similarly for links to entirely new (yet to be created) pages, {{SITE_EDITING}}
will be replaced byt he base URL for such a page, while {{CURRENT_PAGE_TITLE}}
will be replaced by the current wiki page's title:

$wgBADIConfig['site_editing_templates'] = '{{SITE_EDITING}}{{CURRENT_PAGE_TITLE}}&action=edit';

If you don't want links to appear while the user is in other namespaces, you can set this setting:

    $wgBADIConfig['no_namespaces'] = true;

As evident above, most of the configuration will be provided by the user, but our extension does support default values if one only wishes to link  to Wikipedia. This information as well as extension credits could be translated inside BADIPagesCreatedLinks.i18n.php (feel free to let us know if you write any localizations and we may include them).

Warmest regards!
BADI Developer Institute
