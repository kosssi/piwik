<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Referrers;
use Piwik\Cache;
use Piwik\Common;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Singleton;
use Piwik\UrlHelper;

/**
 * Contains methods to access search engine definition data.
 */
class SearchEngine extends Singleton
{
    const OPTION_STORAGE_NAME = 'SearchEngineDefinitions';

    /** @var string location of definition file (relative to PIWIK_INCLUDE_PATH) */
    const DEFINITION_FILE = '/vendor/piwik/searchengine-and-social-definitions/SearchEngines.yml';

    protected $definitionList = null;

    /**
     * Returns list of search engines by URL
     *
     * @return array  Array of ( URL => array( searchEngineName, keywordParameter, path, charset ) )
     */
    public function getSearchEngineDefinitions()
    {
        $cache = Cache::getEagerCache();
        $cacheId = 'SearchEngine-' . self::OPTION_STORAGE_NAME;

        if ($cache->contains($cacheId)) {
            $list = $cache->fetch($cacheId);
        } else {
            $list = $this->loadSearchEngineDefinitions();
            $cache->save($cacheId, $list);
        }

        return $list;
    }

    private function loadSearchEngineDefinitions()
    {
        if ($this->definitionList === null) {
            // Read first from the auto-updated list in database
            $list = Option::get(self::OPTION_STORAGE_NAME);

            if ($list) {
                $this->definitionList = unserialize($list);
            } else {
                // Fallback to reading the bundled list
                $yml = file_get_contents(PIWIK_INCLUDE_PATH . self::DEFINITION_FILE);
                $this->definitionList = $this->loadYmlData($yml);
                Option::set(self::OPTION_STORAGE_NAME, serialize($this->definitionList));

            }
        }

        Piwik::postEvent('Referrer.addSearchEngineUrls', array(&$this->definitionList));

        return $this->definitionList;
    }

    /**
     * Parses the given YML string and caches the resulting definitions
     *
     * @param string $yml
     * @return array
     */
    public function loadYmlData($yml)
    {
        $searchEngines = \Spyc::YAMLLoadString($yml);

        $this->definitionList = $this->transformData($searchEngines);

        return $this->definitionList;
    }

    protected function transformData($searchEngines)
    {
        $urlToInfo = array();
        foreach ($searchEngines as $name => $info) {
            foreach ($info as $urlDefinitions) {
                foreach ($urlDefinitions['urls'] as $url) {
                    $searchEngineData = $urlDefinitions;
                    unset($searchEngineData['urls']);
                    $searchEngineData['name'] = $name;
                    $urlToInfo[$url] = $searchEngineData;
                }
            }
        }
        return $urlToInfo;
    }

    /**
     * Returns list of search engines by name
     *
     * @see core/DataFiles/SearchEngines.php
     *
     * @return array  Array of ( searchEngineName => URL )
     */
    public function getSearchEngineNames()
    {
        $cacheId = 'SearchEngine.getSearchEngineNames';
        $cache = Cache::getTransientCache();
        $nameToUrl = $cache->fetch($cacheId);

        if (empty($nameToUrl)) {
            $searchEngines = $this->getSearchEngineDefinitions();

            $nameToUrl = array();
            foreach ($searchEngines as $url => $info) {
                if (!isset($nameToUrl[$info['name']])) {
                    $nameToUrl[$info['name']] = $url;
                }
            }
            $cache->save($cacheId, $nameToUrl);
        }

        return $nameToUrl;
    }

    /**
     * Returns definitions for the given search engine host
     *
     * @param string $host
     * @return array
     */
    public function getDefinitionByHost($host)
    {
        $searchEngines = $this->getSearchEngineDefinitions();

        if (!array_key_exists($host, $searchEngines)) {
            return array();
        }

        return $searchEngines[$host];
    }

    /**
     * Returns defined parameters for the given search engine host
     * @param string $host
     * @return array
     */
    public function getParameterNamesByHost($host)
    {
        $definition = $this->getDefinitionByHost($host);

        if (empty($definition['params'])) {
            return array();
        }

        return $definition['params'];
    }

    /**
     * Returns defined backlink for the given search engine host
     * @param string $host
     * @return string|null
     */
    public function getBackLinkPatternByHost($host)
    {
        $definition = $this->getDefinitionByHost($host);

        if (empty($definition['backlink'])) {
            return null;
        }

        return $definition['backlink'];
    }

    /**
     * Returns defined charsets for given search engine host
     *
     * @param string $host
     * @return array
     */
    public function getCharsetsByHost($host)
    {
        $definition = $this->getDefinitionByHost($host);

        if (empty($definition['charsets'])) {
            return array();
        }

        return $definition['charsets'];
    }

    /**
     * Extracts a keyword from a raw not encoded URL.
     * Will only extract keyword if a known search engine has been detected.
     * Returns the keyword:
     * - in UTF8: automatically converted from other charsets when applicable
     * - strtolowered: "QUErY test!" will return "query test!"
     * - trimmed: extra spaces before and after are removed
     *
     * Lists of supported search engines can be found in /core/DataFiles/SearchEngines.php
     * The function returns false when a keyword couldn't be found.
     *     eg. if the url is "http://www.google.com/partners.html" this will return false,
     *       as the google keyword parameter couldn't be found.
     *
     * @see unit tests in /tests/core/Common.test.php
     * @param string $referrerUrl URL referrer URL, eg. $_SERVER['HTTP_REFERER']
     * @return array|bool   false if a keyword couldn't be extracted,
     *                        or array(
     *                            'name' => 'Google',
     *                            'keywords' => 'my searched keywords')
     */
    public function extractInformationFromUrl($referrerUrl)
    {
        $referrerParsed = @parse_url($referrerUrl);
        $referrerHost = '';
        if (isset($referrerParsed['host'])) {
            $referrerHost = $referrerParsed['host'];
        }
        if (empty($referrerHost)) {
            return false;
        }
        // some search engines (eg. Bing Images) use the same domain
        // as an existing search engine (eg. Bing), we must also use the url path
        $referrerPath = '';
        if (isset($referrerParsed['path'])) {
            $referrerPath = $referrerParsed['path'];
        }

        // no search query
        if (!isset($referrerParsed['query'])) {
            $referrerParsed['query'] = '';
        }
        $query = $referrerParsed['query'];

        // Google Referrers URLs sometimes have the fragment which contains the keyword
        if (!empty($referrerParsed['fragment'])) {
            $query .= '&' . $referrerParsed['fragment'];
        }

        $searchEngines = $this->getSearchEngineDefinitions();

        $hostPattern = UrlHelper::getLossyUrl($referrerHost);
        /*
         * Try to get the best matching 'host' in definitions
         * 1. check if host + path matches an definition
         * 2. check if host only matches
         * 3. check if host pattern + path matches
         * 4. check if host pattern matches
         * 5. special handling
         */
        if (array_key_exists($referrerHost . $referrerPath, $searchEngines)) {
            $referrerHost = $referrerHost . $referrerPath;
        } elseif (array_key_exists($referrerHost, $searchEngines)) {
            // no need to change host
        } elseif (array_key_exists($hostPattern . $referrerPath, $searchEngines)) {
            $referrerHost = $hostPattern . $referrerPath;
        } elseif (array_key_exists($hostPattern, $searchEngines)) {
            $referrerHost = $hostPattern;
        } elseif (!array_key_exists($referrerHost, $searchEngines)) {
            if (!strncmp($query, 'cx=partner-pub-', 15)) {
                // Google custom search engine
                $referrerHost = 'google.com/cse';
            } elseif (!strncmp($referrerPath, '/pemonitorhosted/ws/results/', 28)) {
                // private-label search powered by InfoSpace Metasearch
                $referrerHost = 'wsdsold.infospace.com';
            } elseif (strpos($referrerHost, '.images.search.yahoo.com') != false) {
                // Yahoo! Images
                $referrerHost = 'images.search.yahoo.com';
            } elseif (strpos($referrerHost, '.search.yahoo.com') != false) {
                // Yahoo!
                $referrerHost = 'search.yahoo.com';
            } else {
                return false;
            }
        }
        $searchEngineName = $searchEngines[$referrerHost]['name'];
        $variableNames = $this->getParameterNamesByHost($referrerHost);

        $key = null;
        if ($searchEngineName === 'Google Images'
            || ($searchEngineName === 'Google' && strpos($referrerUrl, '/imgres') !== false)
        ) {
            if (strpos($query, '&prev') !== false) {
                $query = urldecode(trim(UrlHelper::getParameterFromQueryString($query, 'prev')));
                $query = str_replace('&', '&amp;', strstr($query, '?'));
            }
            $searchEngineName = 'Google Images';
        } elseif ($searchEngineName === 'Google'
            && (strpos($query, '&as_') !== false || strpos($query, 'as_') === 0)
        ) {
            $keys = array();
            $key = UrlHelper::getParameterFromQueryString($query, 'as_q');
            if (!empty($key)) {
                array_push($keys, $key);
            }
            $key = UrlHelper::getParameterFromQueryString($query, 'as_oq');
            if (!empty($key)) {
                array_push($keys, str_replace('+', ' OR ', $key));
            }
            $key = UrlHelper::getParameterFromQueryString($query, 'as_epq');
            if (!empty($key)) {
                array_push($keys, "\"$key\"");
            }
            $key = UrlHelper::getParameterFromQueryString($query, 'as_eq');
            if (!empty($key)) {
                array_push($keys, "-$key");
            }
            $key = trim(urldecode(implode(' ', $keys)));
        }

        if ($searchEngineName === 'Google') {
            // top bar menu
            $tbm = UrlHelper::getParameterFromQueryString($query, 'tbm');
            switch ($tbm) {
                case 'isch':
                    $searchEngineName = 'Google Images';
                    break;
                case 'vid':
                    $searchEngineName = 'Google Video';
                    break;
                case 'shop':
                    $searchEngineName = 'Google Shopping';
                    break;
            }
        }

        if (empty($key)) {
            foreach ($variableNames as $variableName) {
                if ($variableName[0] == '/') {
                    // regular expression match
                    if (preg_match($variableName, $referrerUrl, $matches)) {
                        $key = trim(urldecode($matches[1]));
                        break;
                    }
                } else {
                    // search for keywords now &vname=keyword
                    $key = UrlHelper::getParameterFromQueryString($query, $variableName);
                    $key = trim(urldecode($key));

                    // Special cases: empty or no keywords
                    if (empty($key)
                        && (
                            // Google search with no keyword
                            ($searchEngineName == 'Google'
                                && (empty($query) && (empty($referrerPath) || $referrerPath == '/') && empty($referrerParsed['fragment']))
                            )

                            // Yahoo search with no keyword
                            || ($searchEngineName == 'Yahoo!'
                                && ($referrerParsed['host'] == 'r.search.yahoo.com')
                            )

                            // empty keyword parameter
                            || strpos($query, sprintf('&%s=', $variableName)) !== false
                            || strpos($query, sprintf('?%s=', $variableName)) !== false

                            // search engines with no keyword
                            || $searchEngineName == 'Ixquick'
                            || $searchEngineName == 'Google Images'
                            || $searchEngineName == 'DuckDuckGo')
                    ) {
                        $key = false;
                    }
                    if (!empty($key)
                        || $key === false
                    ) {
                        break;
                    }
                }
            }
        }

        // $key === false is the special case "No keyword provided" which is a Search engine match
        if ($key === null || $key === '') {
            return false;
        }

        if (!empty($key)) {
            $charsets = $this->getCharsetsByHost($referrerHost);

            if (function_exists('iconv')
                && !empty($charsets)
            ) {
                $charset = $charsets[0];
                if (count($charsets) > 1
                    && function_exists('mb_detect_encoding')
                ) {
                    $charset = mb_detect_encoding($key, $charsets);
                    if ($charset === false) {
                        $charset = $charsets[0];
                    }
                }

                $newkey = @iconv($charset, 'UTF-8//IGNORE', $key);
                if (!empty($newkey)) {
                    $key = $newkey;
                }
            }

            $key = Common::mb_strtolower($key);
        }

        return array(
            'name' => $searchEngineName,
            'keywords' => $key,
        );
    }

    /**
     * Return search engine URL by name
     *
     * @see core/DataFiles/SearchEnginges.php
     *
     * @param string $name
     * @return string URL
     */
    public function getUrlFromName($name)
    {
        $searchEngineNames = $this->getSearchEngineNames();
        if (isset($searchEngineNames[$name])) {
            $url = 'http://' . $searchEngineNames[$name];
        } else {
            $url = 'URL unknown!';
        }
        return $url;
    }

    /**
     * Return search engine host in URL
     *
     * @param string $url
     * @return string host
     */
    private function getHostFromUrl($url)
    {
        if (strpos($url, '//')) {
            $url = substr($url, strpos($url, '//') + 2);
        }
        if (($p = strpos($url, '/')) !== false) {
            $url = substr($url, 0, $p);
        }
        return $url;
    }


    /**
     * Return search engine logo path by URL
     *
     * @param string $url
     * @return string path
     * @see plugins/Referrers/images/searchEnginges/
     */
    public function getLogoFromUrl($url)
    {
        $pathInPiwik = 'plugins/Referrers/images/searchEngines/%s.png';
        $pathWithCode = sprintf($pathInPiwik, $this->getHostFromUrl($url));
        $absolutePath = PIWIK_INCLUDE_PATH . '/' . $pathWithCode;
        if (file_exists($absolutePath)) {
            return $pathWithCode;
        }
        return sprintf($pathInPiwik, 'xx');
    }

    /**
     * Return search engine URL for URL and keyword
     *
     * @see core/DataFiles/SearchEnginges.php
     *
     * @param string $url Domain name, e.g., search.piwik.org
     * @param string $keyword Keyword, e.g., web+analytics
     * @return string URL, e.g., http://search.piwik.org/q=web+analytics
     */
    public function getBackLinkFromUrlAndKeyword($url, $keyword)
    {
        if ($keyword === API::LABEL_KEYWORD_NOT_DEFINED) {
            return 'http://piwik.org/faq/general/#faq_144';
        }
        $keyword = urlencode($keyword);
        $keyword = str_replace(urlencode('+'), urlencode(' '), $keyword);
        $host = substr($url, strpos($url, '//') + 2);
        $path = SearchEngine::getInstance()->getBackLinkPatternByHost($host);
        if (empty($path)) {
            return false;
        }
        $path = str_replace("{k}", $keyword, $path);
        return $url . (substr($url, -1) != '/' ? '/' : '') . $path;
    }
}
