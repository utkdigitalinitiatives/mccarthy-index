mccarthy_theme theme, generated from starterkit_theme. Additional information on generating themes can be found in the [Starterkit documentation](https://www.drupal.org/docs/core-modules-and-themes/core-themes/starterkit-theme).

## Advanced search

The front-page `search` view uses Search API's exposed searched-fields selector,
not Facets. The theme displays Title and Author / Creators checkboxes in an
Advanced search disclosure. Selecting both searches either field; leaving both
unchecked preserves the existing search across all indexed full-text fields.
Field selection restricts matching, not relevance weights. Existing relevance
sorting and the title boost remain unchanged.

The view's full-text filter must have **Expose searched fields** enabled, with
the identifier `search_api_fulltext_searched_fields`. This is included in
[`views.view.search.yml`](../../../config/views.view.search.yml). After deploying
the configuration and theme, import configuration and rebuild caches using
`ddev drush cim -y` and `ddev drush cr`. No index changes or reindexing are required.