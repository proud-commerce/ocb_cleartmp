<?php
/**
 * @package   ocb_cleartmp
 * @category  OXID Module
 * @license   GNU License http://opensource.org/licenses/GNU
 * @author    Joscha Krug <krug@marmalade.de> / OXID Community
 * @link      https://github.com/OXIDprojects/ocb_cleartmp
 * @see       https://github.com/OXIDCookbook/ocb_cleartmp
 */

namespace OxidCommunity\OcbClearTmp\Controller\Admin;

use OxidEsales\Facts\Facts;

/**
 * Class NavigationController
 *
 * @package OxidCommunity\OcbClearTmp\Controller\Admin
 */
class NavigationController extends NavigationController_parent
{

    /**
     * Change the full template as there is no block jet in the header.
     *
     * @return string templatename
     */
    public function render()
    {
        $this->_aViewData['prodmode'] = \OxidEsales\Eshop\Core\Registry::getConfig()->isProductiveMode();

        return parent::render();
    }

    /**
     * Method that will be called from the frontend
     * and starts the clearing
     *
     * @throws \Exception
     */
    public function cleartmp()
    {
        $config = \OxidEsales\Eshop\Core\Registry::getConfig();
        $sShopId = $config->getShopId();

        $execCleanup = (bool) \OxidEsales\Eshop\Core\Registry::get(\OxidEsales\Eshop\Core\Request::class)->getRequestParameter('executeCleanup');
        $remoteHosts = (array) $config->getShopConfVar('ocbcleartmpRemoteHosts', \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId(), 'module:ocb_cleartmp');

        if (!$execCleanup && 0 < count($remoteHosts)) {
            $host = parse_url($config->getConfigParam('sShopURL'), PHP_URL_HOST);
            $this->sendRemoteRequests($host, $remoteHosts);
        }

        if (false != \OxidEsales\Eshop\Core\Registry::get(\OxidEsales\Eshop\Core\Request::class)->getRequestParameter('devmode')) {
            $ocbcleartmpDevMode = \OxidEsales\Eshop\Core\Registry::get(\OxidEsales\Eshop\Core\Request::class)->getRequestParameter('devmode');
        }
        $config->saveShopConfVar('bool', 'ocbcleartmpDevMode', $ocbcleartmpDevMode, $sShopId, 'module:ocb_cleartmp');

        $this->deleteFiles();

        return;
    }

    /**
     * Sends a request to remote servers to execute the cleanup on them.
     *
     * @param string   $httpHost
     * @param string[] $remoteHosts
     */
    protected function sendRemoteRequests($httpHost, $remoteHosts)
    {
        $curl = curl_init();
        $postBody = rtrim(file_get_contents('php://input'));
        $postBody = rtrim($postBody, '&') . '&executeCleanup=1';
        $options = [
            CURLOPT_COOKIE         => $_SERVER['HTTP_COOKIE'],
            CURLOPT_HTTPHEADER     => [
                "Host: {$httpHost}",
            ],
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
        ];

        curl_setopt_array($curl, $options);

        $requestUri = $_SERVER['REQUEST_URI'];
        $urlTemplate = "{HOST}{$requestUri}";

        foreach ($remoteHosts as $remoteHost) {
            // Don't send the request to the current instance!
            if (false !== strpos($remoteHost, $_SERVER['SERVER_ADDR'])) {
                continue;
            }
            $fullUrl = strtr($urlTemplate, ['{HOST}' => $remoteHost]);
            curl_setopt($curl, CURLOPT_URL, $fullUrl);
            curl_exec($curl);
        }

        curl_close($curl);
    }


    /**
     * Check wether the developermode is enabled or not
     *
     * @return object
     */
    public function isDevMode()
    {
        return \OxidEsales\Eshop\Core\Registry::getConfig()->getShopConfVar('ocbcleartmpDevMode', \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId(), 'module:ocb_cleartmp');
    }


    /**
     * Check if shop is Enterprise Edition
     *
     * @return bool
     * @throws \Exception
     */
    public function isEEVersion()
    {
        $oFacts = oxNew(Facts::class);
        return ('EE' === $oFacts->getEdition());
    }

    /**
     * Check if picture Cache enabled
     *
     * @return object
     */
    public function isPictureCache()
    {
        return \OxidEsales\Eshop\Core\Registry::getConfig()->getShopConfVar('ocbcleartmpPictureClear', \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId(), 'module:ocb_cleartmp');
    }


    /**
     * Method to remove the files from the cache folder
     * and trigger other options
     * depending on the given option
     *
     * @throws \Exception
     */
    public function deleteFiles()
    {
        $config = \OxidEsales\Eshop\Core\Registry::getConfig();
        $option = \OxidEsales\Eshop\Core\Registry::get(\OxidEsales\Eshop\Core\Request::class)->getRequestParameter('clearoption');
        $sTmpDir = realpath($config->getShopConfVar('sCompileDir'));

        $aFiles = [];

        switch ($option) {
            case 'smarty':
                $aFiles = glob($sTmpDir . '/smarty/*.php');
                break;
            case 'staticcache':
                $aFiles = glob($sTmpDir . '/ocb_cache/*.json');
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/jkrug_cache/*'));
                break;
            case 'language':
                \OxidEsales\Eshop\Core\Registry::getUtils()->resetLanguageCache();
                break;
            case 'database':
                $aFiles = glob($sTmpDir . '/*allfields*.txt');
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/*allviews*.txt'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/*tbdsc*.txt'));
                break;
            case 'complete':
            	\OxidEsales\Eshop\Core\Registry::getUtils()->resetLanguageCache();
                $aFiles = glob($sTmpDir . '/*.txt');
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/*.php'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/smarty/*.php'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/ocb_cache/*.json'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/jkrug_cache/*'));
                if ($this->isPictureCache()) {
                    $aFiles = array_merge($aFiles, glob($config->getPictureDir(false) . 'generated/*'));
                }
                if ($this->isEEVersion()) {
                    $this->clearContentCache();
                }
                break;
            case 'seo':
                $aFiles = glob($sTmpDir . '/*seo.txt');
                break;
            case 'picture':
                $aFiles = glob($config->getPictureDir(false) . 'generated/*');
                break;
            case 'content':
                $this->clearContentCache();
                break;
            case 'allMods':
                $this->removeAllModuleEntriesFromDb();
                $aFiles = glob($sTmpDir . '/*.txt');
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/*.php'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/smarty/*.php'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/ocb_cache/*.json'));
                $aFiles = array_merge($aFiles, glob($sTmpDir . '/jkrug_cache/*'));

                return;
            case 'none':
            default:
                return;
        }

        if (count($aFiles) > 0) {
            foreach ($aFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                } else {
                    $this->clearDir($file);
                }
            }
        }
    }

    /**
     * clears the content Cache
     */
    protected function clearContentCache()
    {
        /* @var $oCache \oxCache */
        $oCache = oxNew('oxcache');
        $oCache->reset();
        /* @var $oRpBackend \oxCacheBackend */
        $oRpBackend = \OxidEsales\Eshop\Core\Registry::get('oxCacheBackend');
        $oRpBackend->flush();
    }

    /**
     * @param $dir
     *
     * @return bool
     */
    public function clearDir($dir)
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                if (is_dir("$dir/$file")) {
                    $this->clearDir("$dir/$file");
                } else {
                    unlink("$dir/$file");
                }
            }

            return rmdir($dir);
        }

        return false;
    }

    /**
     * Remove all module entries from the oxConfig table
     * Will only work if the developer mode is enabled.
     */
    protected function removeAllModuleEntriesFromDb()
    {
        if (false != \OxidEsales\Eshop\Core\Registry::getConfig()->getRequestParameter('devmode')) {
            \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->execute('DELETE FROM `oxconfig` WHERE `OXVARNAME` LIKE \'%aMod%\';');
            \OxidEsales\Eshop\Core\DatabaseProvider::getDb()->execute('DELETE FROM `oxconfig` WHERE `OXVARNAME` LIKE \'%aDisabledModules%\';');
        }
    }
}
