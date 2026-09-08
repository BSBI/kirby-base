# Using Kirby-base as a Kirby CMS plugin

- Install the plugin into your repository

> git submodule add https://github.com/jdrever/kirby-base.git site/plugins/kirby-base

- Create your own Kirby plugin for the additional code for the site, 
- including a composer.json file with an entry to require KirbyBase

```

"require": {
"open-foundations/kirby-base": "*"
},

```

# Architecture

## Service Helpers

KirbyBase ships seven focused service classes that encapsulate distinct concerns.
`KirbyBaseHelper` delegates to all of them internally and exposes the same public API
as before, so consuming sites require no changes.

| Service                  | Responsibility                                                                |
|--------------------------|-------------------------------------------------------------------------------|
| `KirbyFieldReader`       | Reading and type-coercing Kirby fields (page, site, structure, block, user)   |
| `ImageService`           | Resolving images, files, and documents from Kirby fields                      |
| `NavigationService`      | Building `WebPageLink` / `WebPageLinks` / `CoreLink` model objects            |
| `SearchService`          | Search query building, SQLite FTS5 search, analytics, and term highlighting   |
| `CollectionFilterService`| Filtering Kirby `Collection` and `Structure` objects                          |
| `UserService`            | User model building, permission checks, and user mutation                     |
| `UuidResolver`           | Resolving `file://`/`page://` references with a short-lived miss list (one hour, or the `uuidResolver.missTtlSeconds` option), so a dangling reference does not walk the whole site on every request (bsbi-web#732); misses are logged to `logs/uuid-misses.log`. Block snippets and `KirbyFieldReader` use it; site snippets can call `UuidResolver::instance()->fileFromField($field)`. Pair with the **Dangling references** maintenance task, and on localhost/staging the **UUID cache** task after copying content in (the **Cache** task keeps the UUID cache, so live never needs it). Not covered: UUIDs inside Kirbytext tags (`(link: page://…)`), which core resolves; the audit lists those too. |

You can instantiate the services directly in your own code if you want to use them
without going through `KirbyBaseHelper`:

```php
$fieldReader = new KirbyFieldReader(kirby(), site());
$userService = new UserService(kirby(), $fieldReader, fn () => isset($_COOKIE['kirby_session']));

$currentUser = $userService->getCurrentUser();
```

# Content Indexes

See [content-index.md](content-index.md) for a full guide to creating content indexes.


# Extending KirbyBase

- Create a WebPage model class that extends BaseWebPage. Add the fields each page for your site will need.
- Then create whatever other specialised WebPage models you need that extend WebPage
- Create a KirbyHelper class that extends KirbyBaseHelper.
  - Implement getBasicPage to return your basic WebPage class.
  - Implement setBasicPage to populate the fields in your WebPage model
- Then implement setter functions in KirbyHelper for each of the specialised pages

## CoreLinks

- Create a CoreLinkTypes enum, and add has/get functions using the enum 
- to the WebPage model to allow easy handling of core links

```
    /**
     * @param CoreLinkType $linkType
     * @return bool
     */
    public function hasCoreLink(CoreLinkType $linkType): bool {
        $coreLink = $this->getCoreLink($linkType);
        return  ($coreLink->getStatus() !== false);
    }

    /**
     * returns null if no link found.  Use hasCoreLink to check first.
     * @param CoreLinkType $linkType
     * @return CoreLink
     */
    public function getCoreLink(CoreLinkType $linkType): CoreLink
    {
        return $this->coreLinks->getPage($linkType->value);
    }
```

## Lists and Pagination

- Use getSpecificModelList and pass into the parameter $modelListClass  the list class you want to create a 
list for, e.g. EventsList (it must extend BaseList)
- In that list class, overrride getItemType to return the class you want the list to be made up of, 
e.g. Event (it must extend BaseModel)
- If you're using a filter, create a Filter class (extending BaseFilter) to hold the parameters you can filter by, 
and implement a filter function based on the list class name, e.g. filterEventsList
- If you are using pagination, override UsePagination in the model list class.  
You can optionally also override getPaginationPerPage (which is 10 by default) and call the base/pagination snippet, 
passing in the getPagination() function for your class list 


## Forms

See [forms.md](forms.md) for a full guide to creating definition-based forms, including sections and conditional display.

## Menu Pages

Set up a collection called menuPages - this will be used to setMenuPages for the BaseWebPage class.

## File Links

If you want file links to download the associated file, you need to add a controller for file_link which calls the 
redirectToFile function, e.g:

```
    $helper = new KirbyHelper($kirby, $site, $page);
    $helper->redirectToFile($page);
```

## Sitemap

There is a route set for sitemap.xml to generate a sitemap.  It will ignore all templates in the config field 
sitemapExclude

## robots.txt

There is a route set up for robots.txt excluding the kirby folders and search/login folder, plus AI bots.  To add 
additional elements to the robots.txt, create a snippet called robots-txt-additional

## Login and password protection

There is a tab blueprint called permissions.yml which can be added to any page.

The requiredRoles field allows only users with specified roles to be able to access the page.
The password allows the setting of a simple password for that page only.

### Login page
The login functionality requires a page called login to be in the site root.  It should use the base/login snippet to 
display the login form.



# Testing

## Running the tests

```
vendor/bin/phpunit
```

Run a single test file:

```
vendor/bin/phpunit tests/Unit/helpers/UserServiceTest.php
```

## Test coverage

Unit tests live in `tests/Unit/` and follow the same namespace structure as the
classes they cover.

| Test class              | Covers                                                         |
|-------------------------|----------------------------------------------------------------|
| `KirbyFieldReaderTest`  | All field-reading methods across page, site, structure, block  |
| `SearchTextHelperTest`  | Search scoring, highlighting, stop-word filtering              |
| `UserServiceTest`       | Permission checks, user model building, session-cookie guards  |
| `UuidResolverTest`      | Miss list: record, short-circuit, expiry, hit wins, field helpers |
| `DanglingReferenceAuditTaskTest`, `UuidCachePopulateTaskTest` | The two UUID maintenance tasks against a content fixture |

## Writing tests for service classes

Each service can be instantiated with a minimal in-memory Kirby App — no
filesystem content or running web server is needed. The pattern used throughout
the test suite is:

```php
self::$kirby = new App([
    'roots' => ['index' => $tmpDir, 'content' => $contentDir],
    'roles' => [
        ['name' => 'admin',  'title' => 'Admin'],
        ['name' => 'member', 'title' => 'Member'],
    ],
]);

$fieldReader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
$service     = new UserService(self::$kirby, $fieldReader, fn () => false);
```

Pages are created in-memory with `Page::factory()` and users with
`\Kirby\Cms\User::factory()`, so no Kirby Panel or content files are required.

# Updates

## Changing the KirbyBase version number

You need to do this to update the plugin in your project

- Change the composer.json version number
- Commit that change, and add a tag with the version number
- Push that change, along with the new tag

## Getting the latest version of KirbyBase in your project

- Run composer update

## Design system
- See [design-system.md](design-system.md)

## Colour mode
- See [colour-mode.md](colour-mode.md)

## Cookie consent
- See [cookie-consent.md](cookie-consent.md)

## Custom fields
- See [custom-fields.md](custom-fields.md)

## Requirements

The following need to be in place for any site using KirbyBase

- Needs favicons in /assets/favicons - using https://realfavicongenerator.net/
- Needs bootstrap icons in /assets/images/icons - using https://icons.getbootstrap.com/
