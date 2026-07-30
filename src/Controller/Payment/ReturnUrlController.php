<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWorldline\Controller
 */

namespace MoptWorldline\Controller\Payment;

use MoptWorldline\Adapter\WorldlineSDKAdapter;
use MoptWorldline\Bootstrap\Form;
use MoptWorldline\Service\SecureConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Exception;
use Symfony\Component\HttpFoundation\Session\Session;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ReturnUrlController extends AbstractController
{
    public SecureConfigService $secureConfigService;

    private Session $session;

    const RETURN_URL_PATH = 'worldline/payment/finalize-transaction';
    const PAYMENT_PAGES = [
        '/checkout/confirm',
        '/account/order'
    ];

    /**
     * @param SecureConfigService $secureConfigService
     */
    public function __construct(
        SecureConfigService $secureConfigService
    )
    {
        $this->secureConfigService = $secureConfigService;
        $this->session = new Session();
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    #[Route(
        path: '/worldline_serverUrl',
        name: 'worldline.serverUrl',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function saveServerUrl(Request $request): JsonResponse
    {
        $serverUrl = $request->get('serverUrl') ?: null;

        foreach (self::PAYMENT_PAGES as $page) {
            if (stripos($serverUrl, $page)) {
                $url = explode($page, $serverUrl);
                $this->session->set(Form::SESSION_SERVER_URL, $url[0]);
                break;
            }
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @return string
     */
    private function getServerUrl(): string
    {
        return $this->session->get(Form::SESSION_SERVER_URL) ?: '';
    }

    /**
     * @param WorldlineSDKAdapter $adapter
     * @param bool $isLiveMode
     * @return string
     */
    public function getReturnUrl(WorldlineSDKAdapter $adapter, bool $isLiveMode): string
    {
        $server = $this->getServerUrl();
        if (empty($server)) {
            $configField = $isLiveMode ? Form::LIVE_MAIN_RETURN_SERVER_FIELD : Form::MAIN_RETURN_SERVER_FIELD;
            $server = $adapter->getPluginConfig($configField);
        }

        if (empty($server) || !is_string($server)) {
            throw new \RuntimeException(
                'Worldline return URL could not be determined. Set "'
                . ($isLiveMode ? 'Live' : 'Sandbox') . ' main return URL" in the plugin configuration.'
            );
        }

        $server = trim(trim($server), '/');

        return $server . '/' . self::RETURN_URL_PATH;
    }
}
