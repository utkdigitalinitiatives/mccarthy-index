mccarthy_theme theme, generated from starterkit_theme. Additional information on generating themes can be found in the [Starterkit documentation](https://www.drupal.org/docs/core-modules-and-themes/core-themes/starterkit-theme).

## Advanced search

The front-page `search` view uses Search API's exposed searched-fields selector,
not Facets. The theme displays Title and Author / Creators checkboxes in an
Advanced search disclosure. Selecting both searches either field; leaving both
unchecked preserves the existing search across all indexed full-text fields.
Field selection restricts matching, not relevance weights. Existing relevance
sorting and the title boost remain unchanged.

Multiword searches require all words, in any order. When both fields are
selected, the words can match across either field. Title and author fields use
Solr's Edge NGram full-text type to match word prefixes of at least two
characters (for example, `me` matches `Melville`).
Other indexed fields retain their existing full-text types.
Queries containing only discarded words or punctuation display a validation
message instead of returning every record. An empty search still browses all
records.
Keyword validation errors appear only below the search input; Drupal's Inline
Form Errors settings suppress the duplicate summary for that input.

The exposed-form template renders only the form contents. Drupal's Form API
provides the outer `<form>` element and its attributes; adding another form
inside the template creates invalid nested forms and disrupts AJAX submission.

The view's full-text filter must have **Expose searched fields** enabled, with
the identifier `search_api_fulltext_searched_fields`. This is included in
[`views.view.search.yml`](../../../config/views.view.search.yml). After deploying
the configuration and theme, import configuration and rebuild caches using
`ddev drush cim -y` and `ddev drush cr`. The Solr configset must include the
`text_edge` field type provided by Search API Solr. After importing the prefix
field types and two-character tokenizer setting, rebuild the records index:
`ddev drush search-api:reset-tracker records` followed by
`ddev drush search-api:index records`. Prefix matching will not be available
until the records have been reindexed.