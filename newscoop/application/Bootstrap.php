<?php
/**
 * @package Newscoop
 * @copyright 2011 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

use Newscoop\DoctrineEventDispatcherProxy,
    Doctrine\Common\ClassLoader,
    Doctrine\Common\Annotations\AnnotationReader,
    Doctrine\ODM\MongoDB\DocumentManager,
    Doctrine\MongoDB\Connection,
    Doctrine\ODM\MongoDB\Configuration,
    Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;

class Bootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    protected function _initAutoloader()
    {
        $options = $this->getOptions();
        set_include_path(implode(PATH_SEPARATOR, array_map('realpath', $options['autoloader']['dirs'])) . PATH_SEPARATOR . get_include_path());
        $autoloader = Zend_Loader_Autoloader::getInstance();
        $autoloader->setFallbackAutoloader(TRUE);

        // autoload symfony service container
        $autoloader->pushAutoloader(function($class) {
            require_once APPLICATION_PATH . "/../library/fabpot-dependency-injection-07ff9ba/lib/{$class}.php";
        }, 'sfService');

        // autoload symfony event dispatcher
        $autoloader->pushAutoloader(function($class) {
            require_once APPLICATION_PATH . "/../library/fabpot-event-dispatcher-782a5ef/lib/{$class}.php";
        }, 'sfEvent');

        // fix adodb loading error
        $autoloader->pushAutoloader(function($class) {
            return;
        }, 'ADO');

        $autoloader->pushAutoloader(function($class) {
            require_once 'smarty3/sysplugins/' . strtolower($class) . '.php';
        }, 'Smarty');

        $GLOBALS['g_campsiteDir'] = realpath(APPLICATION_PATH . '/../');

        return $autoloader;
    }

    protected function _initSession()
    {
        $options = $this->getOptions();
        if (!empty($options['session'])) {
            Zend_Session::setOptions($options['session']);
        }
        Zend_Session::start();

        foreach ($_COOKIE as $name => $value) { // remove unused cookies
            if ($name[0] == 'w' && strrpos('_height', $name) !== FALSE) {
                setcookie($name, '', time() - 3600);
            }
        }
    }

    /**
     * TODO the name of this method is named confusing, container for what? a zend navigation container? obviously not..
     */
    protected function _initContainer()
    {
        $this->bootstrap('autoloader');
        $container = new sfServiceContainerBuilder($this->getOptions());
        $container['config'] = $this->getOptions();

        $this->bootstrap('doctrine');
        $doctrine = $this->getResource('doctrine');
        $container->setService('em', $doctrine->getEntityManager());

        $this->bootstrap('view');
        $container->setService('view', $this->getResource('view'));

        $container->register('image', 'Newscoop\Image\ImageService')
            ->addArgument('%image%')
            ->addArgument(new sfServiceReference('em'));

        $container->register('user', 'Newscoop\Services\UserService')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(Zend_Auth::getInstance());

        $container->register('user.list', 'Newscoop\Services\ListUserService')
            ->addArgument('%config%')
            ->addArgument(new sfServiceReference('em'));

        $container->register('user.token', 'Newscoop\Services\UserTokenService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('user_type', 'Newscoop\Services\UserTypeService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('user_points', 'Newscoop\Services\UserPointsService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('user_attributes', 'Newscoop\Services\UserAttributeService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('author', 'Newscoop\Services\AuthorService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('audit', 'Newscoop\Services\AuditService')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(new sfServiceReference('user'));

        $container->register('comment', 'Newscoop\Services\CommentService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('community_feed', 'Newscoop\Services\CommunityFeedService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('audit.maintenance', 'Newscoop\Services\AuditMaintenanceService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('dispatcher', 'Newscoop\Services\EventDispatcherService')
            ->setConfigurator(function($service) use ($container) {
                foreach ($container->getParameter('listener') as $listener) {
                    $listenerService = $container->getService($listener);
                    $listenerParams = $container->getParameter($listener);
                    foreach ((array) $listenerParams['events'] as $event) {
                        $service->connect($event, array($listenerService, 'update'));
                    }
                }
            });

        $container->register('user.topic', 'Newscoop\Services\UserTopicService')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(new sfServiceReference('dispatcher'));


        $container->register('auth.adapter', 'Newscoop\Services\Auth\DoctrineAuthService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('auth.adapter.social', 'Newscoop\Services\Auth\SocialAuthService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('email', 'Newscoop\Services\EmailService')
            ->addArgument('%email%')
            ->addArgument(new sfServiceReference('view'))
            ->addArgument(new sfServiceReference('user.token'));

        $container->register('ingest.settings', 'Newscoop\News\SettingsService')
            ->addArgument(new sfServiceReference('odm'));

        $container->register('ingest.item', 'Newscoop\News\ItemService')
            ->addArgument(new sfServiceReference('odm'))
            ->addArgument(new sfServiceReference('ingest.settings'));

        $container->register('ingest.feed', 'Newscoop\News\FeedService')
            ->addArgument(new sfServiceReference('odm'))
            ->addArgument(new sfServiceReference('ingest.item'));

        $container->register('blog', 'Newscoop\Services\BlogService')
            ->addArgument('%blog%');

        $container->register('comment_notification', 'Newscoop\Services\CommentNotificationService')
            ->addArgument(new sfServiceReference('email'))
            ->addArgument(new sfServiceReference('comment'))
            ->addArgument(new sfServiceReference('user'));

        $container->register('user.search', 'Newscoop\Services\UserSearchService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('content.publication', 'Newscoop\Content\PublicationService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('content.section', 'Newscoop\Content\SectionService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('content.type', 'Newscoop\Content\ContentTypeService')
            ->addArgument(new sfServiceReference('em'));
        
        $container->register('stat', 'Newscoop\Services\StatService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('view.helper.thumbnail', 'Newscoop\Image\ThumbnailViewHelper')
            ->addArgument(new sfServiceReference('image'));

        $container->register('view.helper.rendition', 'Newscoop\Image\RenditionViewHelper')
            ->addArgument(new sfServiceReference('image'));

        $container->register('image.rendition', 'Newscoop\Image\RenditionService')
            ->addArgument('%config%')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(new sfServiceReference('image'));

        $container->register('image.search', 'Newscoop\Image\ImageSearchService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('package', 'Newscoop\Package\PackageService')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(new sfServiceReference('image'));

        $container->register('package.search', 'Newscoop\Package\PackageSearchService')
            ->addArgument(new sfServiceReference('em'));

        $container->register('subscription', 'Newscoop\Subscription\SubscriptionFacade')
            ->addArgument(new sfServiceReference('em'));

        $container->register('subscription.section', 'Newscoop\Subscription\SectionFacade')
            ->addArgument(new sfServiceReference('em'));

        $container->register('subscription.ip', 'Newscoop\Subscription\IpFacade')
            ->addArgument(new sfServiceReference('em'));

        $container->register('random', 'Newscoop\Random');

        $container->register('webcode', 'Newscoop\WebcodeFacade')
            ->addArgument(new sfServiceReference('em'))
            ->addArgument(new sfServiceReference('random'));

        Zend_Registry::set('container', $container);
        return $container;
    }

    /**
     * @todo pass container to allow lazy dispatcher loading
     */
    protected function _initEventDispatcher()
    {
        $this->bootstrap('container');
        $container = $this->getResource('container');

        DatabaseObject::setEventDispatcher($container->getService('dispatcher'));
        DatabaseObject::setResourceNames($container->getParameter('resourceNames'));

        $container->getService('em')
            ->getEventManager()
            ->addEventSubscriber(new DoctrineEventDispatcherProxy($container->getService('dispatcher')));
    }

    protected function _initPlugins()
    {
        $options = $this->getOptions();
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new Application_Plugin_ContentType());
        $front->registerPlugin(new Application_Plugin_Upgrade());
        $front->registerPlugin(new Application_Plugin_CampPluginAutoload());
        $front->registerPlugin(new Application_Plugin_Auth($options['auth']));
        $front->registerPlugin(new Application_Plugin_Acl($options['acl']));
        $front->registerPlugin(new Application_Plugin_Locale());
    }

    protected function _initRouter()
    {
        $front = Zend_Controller_Front::getInstance();
        $router = $front->getRouter();
        $options = $this->getOptions();

        $router->addRoute(
            'content',
            new Zend_Controller_Router_Route(':language/:issue/:section/:articleNo/:articleUrl', array(
                'module' => 'default',
                'controller' => 'index',
                'action' => 'index',
                'articleUrl' => null,
                'articleNo' => null,
                'section' => null,
                'issue' => null,
                'language' => null,
            ), array(
                'language' => '[a-z]{2}',
            )));

         $router->addRoute(
            'webcode',
            new Zend_Controller_Router_Route(':webcode', array(
                'module' => 'default'
            ), array(
                'webcode' => '[\+\s@][0-9a-z]{5,6}',
            )));

         $router->addRoute(
            'language/webcode',
            new Zend_Controller_Router_Route(':language/:webcode', array(
            ), array(
                'module' => 'default',
                'language' => '[a-z]{2}',
                'webcode' => '^[\+\s@][0-9a-z]{5,6}',
            )));

        $router->addRoute(
            'confirm-email',
            new Zend_Controller_Router_Route('confirm-email/:user/:token', array(
                'module' => 'default',
                'controller' => 'register',
                'action' => 'confirm',
            )));

        $router->addRoute(
            'user',
            new Zend_Controller_Router_Route('user/profile/:username/:action', array(
                'module' => 'default',
                'controller' => 'user',
                'action' => 'profile',
            )));

        $router->addRoute('image',
            new Zend_Controller_Router_Route_Regex($options['image']['cache_url'] . '/(.*)', array(
                'module' => 'default',
                'controller' => 'image',
                'action' => 'cache',
            ), array(
                1 => 'src',
            ), $options['image']['cache_url'] . '/%s'));

         $router->addRoute('rest',
             new Zend_Rest_Route($front, array(), array(
                 'admin' => array(
                     'slideshow-rest',
                     'subscription-rest',
                     'subscription-section-rest',
                     'subscription-ip-rest',
                 ),
             )));
    }

    protected function _initActionHelpers()
    {
        require_once APPLICATION_PATH . '/controllers/helpers/Smarty.php';
        Zend_Controller_Action_HelperBroker::addHelper(new Action_Helper_Smarty());
    }

    protected function _initTranslate()
    {
        $options = $this->getOptions();

        $translate = new Zend_Translate(array(
            'adapter' => 'array',
            'disableNotices' => TRUE,
            'content' => $options['translation']['path'],
        ));

        Zend_Registry::set('Zend_Translate', $translate);
    }

    protected function _initOdm()
    {
        if (!extension_loaded('mongo')) {
            return null;
        }

        return null;

        $config = new Configuration();
        $config->setProxyDir(APPLICATION_PATH . '/../cache');
        $config->setProxyNamespace('Proxies');

        $config->setHydratorDir(APPLICATION_PATH . '/../cache');
        $config->setHydratorNamespace('Hydrators');

        require_once 'Doctrine/ODM/MongoDB/Mapping/Annotations/DoctrineAnnotations.php';

        $reader = new AnnotationReader();
        $config->setMetadataDriverImpl(new AnnotationDriver($reader, APPLICATION_PATH . '/../library/Newscoop/News'));

        $config->setDefaultDB('newscoop');

        $odm = DocumentManager::create(new Connection(), $config);

        $this->bootstrap('container');
        $this->getResource('container')->setService('odm', $odm);

        return $odm;
    }

    /**
     */
    protected function _initLog()
    {
        $writer = new Zend_Log_Writer_Syslog(array('application' => 'Newscoop'));
        $log = new Zend_Log($writer);
        \Zend_Registry::set('log', $log);
        return $log;
    }
}
