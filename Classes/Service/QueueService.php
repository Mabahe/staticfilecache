<?php
/**
 * Queue service
 *
 * @author  Tim Lochmüller
 */

declare(strict_types=1);

namespace SFC\Staticfilecache\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use SFC\Staticfilecache\Utility\DateTimeUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Queue service
 */
class QueueService extends AbstractService
{

    /**
     * Queue table
     */
    const QUEUE_TABLE = 'tx_staticfilecache_queue';

    /**
     * Run the queue
     *
     * @param int $limitItems
     */
    public function run($limitItems = 0)
    {
        define('SFC_QUEUE_WORKER', true);

        $limit = $limitItems > 0 ? (int)$limitItems : 999;

        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable(self::QUEUE_TABLE);
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $rows = $queryBuilder->select('*')
            ->from(self::QUEUE_TABLE)
            ->where($queryBuilder->expr()->eq('call_date', $queryBuilder->createNamedParameter(0)))
            ->setMaxResults($limit)
            ->execute()
            ->fetchAll();

        foreach ($rows as $runEntry) {
            $this->runSingleRequest($runEntry);
        }
    }

    /**
     * Run a single request with guzzle
     *
     * @param array $runEntry
     */
    protected function runSingleRequest(array $runEntry)
    {
        try {
            $client = $this->getCallableClient(parse_url($runEntry['cache_url'], PHP_URL_HOST));
            $response = $client->get($runEntry['cache_url']);
            $statusCode = $response->getStatusCode();
        } catch (\Exception $ex) {
            $statusCode = 900;
        }
        $data = [
            'call_date' => time(),
            'call_result' => $statusCode,
        ];

        if ($statusCode !== 200) {
            // Call the flush, if the page is not accessable
            $cache = GeneralUtility::makeInstance(CacheService::class)->getCache();
            $cache->flushByTag('sfc_pageId_' . $runEntry['page_uid']);
            if ($cache->has($runEntry['cache_url'])) {
                $cache->remove($runEntry['cache_url']);
            }
        }


        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable(self::QUEUE_TABLE);
        $connection->update(
            self::QUEUE_TABLE,
            $data,
            ['uid' => (int)$runEntry['uid']]
        );
    }

    /**
     * Alternativ for runSingleRequest (not used at the moment)
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    public function runMultiRequest(array $data, $options = [])
    {

        $curly = [];
        $result = [];
        $mh = curl_multi_init();

        foreach ($data as $id => $d) {
            $curly[$id] = curl_init();

            $url = (is_array($d) && !empty($d['cache_url'])) ? $d['cache_url'] : $d;
            curl_setopt($curly[$id], CURLOPT_URL, $url);
            curl_setopt($curly[$id], CURLOPT_HEADER, 0);
            curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curly[$id], CURLOPT_FRESH_CONNECT, true);
            curl_setopt($curly[$id], CURLOPT_TIMEOUT_MS, 2000);
            curl_setopt($curly[$id], CURLOPT_COOKIE, 'staticfilecache=1');
            curl_setopt($curly[$id], CURLOPT_USERAGENT, 'Staticfilecache Crawler');

            if (is_array($d)) {
                if (!empty($d['post'])) {
                    curl_setopt($curly[$id], CURLOPT_POST, 1);
                    curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
                }
            }

            // extra options?
            if (!empty($options)) {
                curl_setopt_array($curly[$id], $options);
            }

            curl_multi_add_handle($mh, $curly[$id]);
        }

        // execute the handles
        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        // get content and remove handles
        foreach ($curly as $id => $c) {
            $result[$id] = [
                'call_date' => time(),
                'call_result' => curl_getinfo($c),
                'page_uid' => $data[$id]['page_uid'],

            ];
            curl_multi_remove_handle($mh, $c);
        }

        // all done
        curl_multi_close($mh);

        return $result;
    }

    /**
     * Cleanup the cache queue
     */
    public function cleanup()
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $queryBuilder = $connectionPool->getQueryBuilderForTable(self::QUEUE_TABLE);
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $rows = $queryBuilder->select('uid')
            ->from(self::QUEUE_TABLE)
            ->where($queryBuilder->expr()->gt('call_date', $queryBuilder->createNamedParameter(0)))
            ->execute()
            ->fetchAll();

        $connection = $connectionPool->getConnectionForTable(self::QUEUE_TABLE);
        foreach ($rows as $row) {
            $connection->delete(self::QUEUE_TABLE, ['uid' => $row['uid']]);
        }
    }

    /**
     * Add identifiert to Queue
     *
     * @param string $identifier
     */
    public function addIdentifier($identifier)
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable(self::QUEUE_TABLE);
        $queryBuilder
            ->getRestrictions()
            ->removeAll();
        $where = $queryBuilder->expr()->andX([
            $queryBuilder->expr()->eq('cache_url', $queryBuilder->createNamedParameter($identifier)),
            $queryBuilder->expr()->eq('call_date', $queryBuilder->createNamedParameter(0))
        ]);
        $rows = $queryBuilder->select('uid', 'TSconfig')
            ->from(self::QUEUE_TABLE)
            ->where($where)
            ->execute()
            ->fetchAll();

        if (!empty($rows)) {
            return;
        }

        $data = [
            'cache_url' => $identifier,
            'page_uid' => 0,
            'invalid_date' => time(),
            'call_result' => ''
        ];
        $connection = $connectionPool->getConnectionForTable(self::QUEUE_TABLE);
        $connection->insert(
            self::QUEUE_TABLE,
            $data
        );
    }

    /**
     * Get a cllable client
     *
     * @param string $domain
     * @return Client
     * @throws \Exception
     */
    protected function getCallableClient($domain)
    {
        if (!class_exists(Client::class) || !class_exists(CookieJar::class)) {
            throw new \Exception('You need guzzle to handle the Queue Management', 1236728342);
        }
        $jar = GeneralUtility::makeInstance(CookieJar::class);
        $cookie = GeneralUtility::makeInstance(SetCookie::class);
        $cookie->setName('staticfilecache');
        $cookie->setValue('1');
        $cookie->setPath('/');
        $cookie->setExpires(DateTimeUtility::getCurrentTime() + 30);
        $cookie->setDomain($domain);
        $jar->setCookie($cookie);
        $options = [
            'cookies' => $jar,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:47.0) Gecko/20100101 Firefox/47.0'
            ]
        ];
        return GeneralUtility::makeInstance(Client::class, $options);
    }
}