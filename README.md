# BADIPagesCreatedLinks

The BADI Pages Created Links extension for Mediawiki
([Mediawiki page](https://www.mediawiki.org/wiki/Extension:BADI_Pages_Created_Links))
allows display of links in one's Mediawiki toolbox area which lead to
other wiki or wiki-like sites whereby links will be colored differently
depending on whether the page there has been created yet or not.

The status of whether it was created yet or not is determined by
`Last-Modified` or `HTTP 200` `HEAD` requests.

## Use cases

- A federation of wikis covering similar topics
- One might point to discussion software to allow further extended
  discussions about the topics of the wiki

## Usage

To use this extension, simply put the `BADIPagesCreatedLinks` folder containing
the extension code inside your wiki's /extensions folder and include this
line in your `LocalSettings.php`:

```php
wfLoadExtension('BADIPagesCreatedLinks');
```

After this line, you can then optionally use any of the `$wgBADIConfig`
configuration properties inside of `LocalSettings.php` (do not edit the
extension files as full configuration should be possible inside
`LocalSettings.php` alone, also potentially allowing you to update to
any future version of our extension with a minimum of hassle).

## Configuration

### `titles`, `sites`, `sites_editing`

The following three properties are the ones you will most likely wish to
modify (unless you only wish to link your pages to Wikipedia).

Each of these properties is an array keyed to a language code (if you wish to
provide language-specific link information) or to the word "default" which in
turn lead to an array of values, respectively:

1. ***titles***: site title
2. ***sites***: a template for the base URL for when the page has already been
    created. `{{LANGUAGE}}`, if present, will be replaced by the current
    language code. Mediawiki sites will probably require a slash at the end.
3. ***sites_editing***: a template for the base URL for when the page has not
    yet been created and will be edited. `{{LANGUAGE}}`, if present, will be
    replaced by the current language code.

They must all be the same length.

"default" can be used in place of a language to indicate the default values
and patterns.

For example, the following allows us to link to Wikipedia and Bahaikipedia
while allowing a different pattern for English, since "en." is not used
in the URL:

```php
$wgBADIConfig['titles']['default'] = ['Wikipedia', 'Bahaikipedia'];
$wgBADIConfig['sites']['default'] = [
    'https://{{LANGUAGE}}.wikipedia.org/wiki/',
    'https://{{LANGUAGE}}.bahaikipedia.org/'
];
$wgBADIConfig['sites_editing']['default'] = [
    'https://{{LANGUAGE}}.wikipedia.org/w/index.php?title=',
    'https://{{LANGUAGE}}.bahaikipedia.org/index.php?title='
];

$wgBADIConfig['titles']['en'] = ['Wikipedia', 'Bahaikipedia'];
$wgBADIConfig['sites']['en'] = [
    'https://{{LANGUAGE}}.wikipedia.org/wiki/',
    'https://bahaikipedia.org/'
];
$wgBADIConfig['sites_editing']['en'] = [
    'https://{{LANGUAGE}}.wikipedia.org/w/index.php?title=',
    'https://bahaikipedia.org/index.php?title='
];
```

The defaults are effectively:

```php
$wgBADIConfig['titles']['default'] = ['Wikipedia'];
$wgBADIConfig['sites']['default'] = ['https://{{LANGUAGE}}.wikipedia.org/wiki/'];
$wgBADIConfig['sites_editing']['default'] = [
    'https://{{LANGUAGE}}.wikipedia.org/w/index.php?title='
];
```

An item in a "sites" language array can be set to `NULL` if a link should
not be generated for that particular language.

### Markup config

Although it is not necessary to change the default values, this extension
also allows you to configure the structure for the markup that will be
produced.

The markup config is built immediately before the external sites header.

#### `external_intro`

Language keyed array for message to introduce external links:

```php
$wgBADIConfig['external_intro']['default'] = 'External pages with same title: ';
```

#### `external_sites_templates`

The template for configuring the entire block that will be added
as a whole is "external_sites_templates:

```php
$wgBADIConfig['external_sites_templates'] = <<<HERE
    <li>{{LOCALIZED_INTRO}}
        <ul>
            {{LINK_ITEMS}}
        </ul>
    </li>
HERE;
```

It allows two variables, the first being `{{LOCALIZED_INTRO}}` which is
merely the introductory text for the links (from `external_intro`) and
`{{LINK_ITEMS}}` which are the individual line items containing the links.

#### `external_site_templates` (and `createdLinkInlineStyles`/`uncreatedLinkInlineStyles` and `createdLinkClass`/`uncreatedLinkClass`)

Each individual line item also has a template, "external_site_templates":

```php
$wgBADIConfig['external_site_templates'] =
    '<li><a class="{{CLASS}}" {{STYLES}} href="{{LOCALIZED_LINK}}">{{LOCALIZED_TITLE}}</a></li>'."\n";
```

The template variable `{{CLASS}}` will be determined according to whether
the page has been created yet or not. (The default values will either be
"external" or "new"--see below.)

The template variable `{{STYLES}}` can allow inline styling information for
convenient styling of individual links, also sensitive to whether the page
has been created yet or not.

These can be added via:

```php
$wgBADIConfig['createdLinkInlineStyles'] = ''; // e.g., font-weight:bold;
$wgBADIConfig['uncreatedLinkInlineStyles'] = ''; // e.g., 'font-style:italic';
```

Note, however, that the `{{CLASS}}` variable is really a better choice
as it allows control from an external stylesheet and can take advantage
of the already-familiar styling differences within Mediawiki
as far as external (which we interpret here as already-created) links or
new ones (styled the same as though they were internal links, by default in
orange color).

The name of the classes that will be created (for created or as-yet-uncreated
pages) can also be configured, though one may wish to just use the default
classes (not necessary to specify them if using the default), since these use
the already familiar Mediawiki styling:

```php
$wgBADIConfig['createdLinkClass'] = 'external';
$wgBADIConfig['uncreatedLinkClass'] = 'new';
```

It is also possible, though probably not necessary for most people, to
configure the link structure for both already-created links and new links.
If there is interest, we could change the code to make these differ by
language and/or site.

`{{LOCALIZED_LINK}}` will be patterned after
`$wgBADIConfig['site_and_title_templates']` (see below).

`{{LOCALIZED_TITLE}}` will come from `$wgBADIConfig['titles']`

#### `site_and_title_templates`

The variable `{{SITE}}` will be replaced by the base URL for an already created
external page (`$wgBADIConfig['sites']` with `{{LANGUAGE}}` substituted within
the site for the locale code), while `{{CURRENT_PAGE_TITLE}}` will be replaced
by the current wiki page's title:

```php
$wgBADIConfig['site_and_title_templates'] = '{{SITE}}{{CURRENT_PAGE_TITLE}}';
```

#### `site_editing_templates`

Similarly for links to entirely new (yet to be created) pages, `{{SITE_EDITING}}`
will be replaced by the base URL for such a page, while `{{CURRENT_PAGE_TITLE}}`
will be replaced by the current wiki page's title:

```php
$wgBADIConfig['site_editing_templates'] =
    '{{SITE_EDITING}}{{CURRENT_PAGE_TITLE}}&action=edit';
```

#### `no_namespaces`

If you don't want links to appear while the user is in other namespaces,
you can set this setting:

```php
$wgBADIConfig['no_namespaces'] = true;
```

#### `user-agent`

This doesn't need to be changed from the default of
`"BADI Mediawiki page-created checker"`. However, explicitly
setting it to the empty string will get a `HTTP 403` error from
Wikipedia (but not custom Mediawiki apparently unless so configured).

#### `stream_context`

This option can be used if one needs to change more than the user-agent
for the HTTP HEAD request.

#### Caching (`no_cache`, `cache_existing`, `cache_nonexisting`, `cache_existing_timeout`, `cache_nonexisting_timeout`)

Configuration exists to allow caching. Caching is on by default and should
remain on, but if needed for development testing, you may disable as follows:

```php
$wgBADIConfig['no_cache'] = true;
```

Also for debugging only, you may wish to set either of this settings to `false`:
```php
$wgBADIConfig['cache_existing'] = false;
$wgBADIConfig['cache_nonexisting'] = false;
```

The caching of pages found to exist or not exist are given separate timeouts.
The following list the default settings, with the existing page timeout
larger since it may be of less concern (if not likelihood) that an existing
page was deleted as opposed to a page coming into existence.

```php
$wgBADIConfig['cache_existing_timeout'] = 31104000; // a year (12 * 30 * 24 * 60 * 60)
$wgBADIConfig['cache_nonexisting_timeout'] = 2592000; // a month (30 * 24 * 60 * 60)
```

### Defaulting and i18n

As evident above, most of the configuration will be provided by the user
(as far as content and external wiki site links), but our extension does
support default values if one only wishes to link to Wikipedia. This
information as well as extension credits could be translated inside
`/BADIPagesCreatedLinks/i18n/en.json` (feel free to let us know if you
write any localizations and we may include them).

## Dedication

This extension is dedicated to our Baha'i friends in Iran who, without
responding in kind, but on the contrary, who persevere in demonstrating their
loyalty and services to their communities and country, despite the
government-sponsored persecution so insidiously leagued against them
(including a denial of education itself! not to mention jobs, pensions,
and businesses and other abuses or harassment, even of poor school children).
No doubt Iran as a whole will return to its prior glories as soon as it
stops its patently false propaganda, trumped up charges and imprisonments,
and becomes concerned with elevating the status of all of its citizens,
whether women, ethnic minorities, or Baha'is (the largest independent
religious minority in the country). Civilized countries do not come anywhere
close to doing things like denying education to its own citizens, citizens
who are moreover loyal to its authority! Is there any excuse for such
backwards behavior? Iran no doubt has yet greater contributions to
make to civilization, if it will only be truly enabled to do so.

## Immediate to-dos

1. Cache! (add to async queue); until cache obtained, show as external
    link.
  1. Review old incomplete `caching` branch for ideas
  1. Add to <https://www.mediawiki.org/wiki/Database_field_prefixes>
      (to avoid future name clashes)
  1. Resources
    - <https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates>
    - <https://www.mediawiki.org/wiki/Manual:Database_access (to query)
    - <https://www.mediawiki.org/wiki/Manual:Job_queue/For_developers> (for
      adding an async job)
  1. Also need to have task to recheck orange link(s), preferably
    activatable from admin page or a specific (category) page itself;
    may also wish to allow rechecking blue links which may have since
    been deleted.
  1. Recheck all links
1. Update <https://www.mediawiki.org/wiki/Extension:BADI_Pages_Created_Links>
    when more functional, if necessary forwarding to `BADIPagesCreatedLinks`.

## Medium priority to-dos

1. Allow more precise namespace config (whitelist or blacklist)
1. Allow admin page to customize renames (have separate table column) or
    have renames specified as properties within (category) pages.

## Lower priority to-dos

1. Reconsider and possibly integrate `BADIPingback` branch (pingbacks,
    trackbacks, refbacks, and the callback linkback; have blogs and forum
    content be displayed inside wikis
