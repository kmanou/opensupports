<?php
use RedBeanPHP\Facade as RedBean;

abstract class Controller {
    private static $dataRequester;
    private static $appInstance;

    /**
     * Instance-related stuff
    */
    abstract public function handler();
    abstract public function validations();

    public function getHandler() {
        return function ($request, $response, $args) {
            Response::init($response);

            try {
                if(RedBean::testConnection() && !Setting::isTableEmpty()) {
                    Session::getInstance()->setSessionPrefix(Setting::getSetting('session-prefix')->getValue());
                }
                $this->validate();
                $this->handler();
            } catch (\Exception $exception) {
                Response::respondError($exception->getMessage(), $exception);
            }

            return Response::getPsrResponse();
        };
    }

    public function validate() {
        $validator = new Validator();

        $validator->validate($this->validations());
    }

    public static function init() {
        // Request data is read straight from PHP's own superglobals rather
        // than through the PSR-7 request object: it is populated by PHP
        // itself the same way regardless of which web framework is in
        // front of it, so this keeps the exact behavior of Slim 2's
        // $app->request()->get()/post() (which did the same thing) without
        // depending on Slim's request/body-parsing internals at all.
        self::$dataRequester = function ($key) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                return $_GET[$key] ?? null;
            } else {
                return $_POST[$key] ?? null;
            }
        };
    }

    public static function setDataRequester($dataRequester) {
        self::$dataRequester = $dataRequester;
    }

    public static function request($key, $secure = false) {
        $result = call_user_func(self::$dataRequester, $key);

        if($key === 'email' || $key === 'newEmail') {
            return strtolower($result);
        }

        if($secure) {
            $config = HTMLPurifier_Config::createDefault();
            $purifier = new HTMLPurifier($config);
            return $purifier->purify($result);
        } else {
            return $result;
        }
    }

    public static function getLoggedUser() {
        $session = Session::getInstance();

        if ($session->isStaffLogged()) {
            return Staff::getUser($session->getUserId());
        } else {
            $user = User::getUser($session->getUserId());
            if($session->getTicketNumber()) $user->ticketNumber = $session->getTicketNumber();

            return $user;
        }
    }

    public static function isUserLogged() {
        $session = Session::getInstance();

        return $session->checkAuthentication(array(
            'userId' => Controller::request('csrf_userid'),
            'token' => Controller::request('csrf_token')
        ));
    }

    public static function isStaffLogged($level = 1) {
        return Controller::isUserLogged() && (Controller::getLoggedUser()->level >= $level);
    }

    public static function setAppInstance($app) {
        self::$appInstance = $app;
    }

    public static function getAppInstance() {
        return self::$appInstance;
    }

    public function uploadImages($forceUpload = false) {
        $allowAttachments = Setting::getSetting('allow-attachments')->getValue();
        $totalImages = Controller::request('images') * 1;

        if(!$allowAttachments && !$forceUpload) return [];
        if(!$totalImages) return [];

        $maxSize = Setting::getSetting('max-size')->getValue();

        $fileUploader = FileUploader::getInstance();
        $fileUploader->setMaxSize($maxSize);

        $allImagesValidSize = true;

        for($i=0;$i<$totalImages;$i++) {
            $allImagesValidSize = $allImagesValidSize && $fileUploader->isSizeValid($_FILES["image_$i"]);
        }

        if(!$allImagesValidSize) throw new RequestException(ERRORS::INVALID_FILE);

        $imagePaths = [];
        $url = Setting::getSetting('url')->getValue();
        for($i=0;$i<$totalImages;$i++) {
            $fileUploader->upload("image_$i");
            $imagePaths[] = $url . '/api/system/download?file=' . $fileUploader->getFileName();
        }

        return $imagePaths;
    }

    public function uploadFile($forceUpload = false) {
        $allowAttachments = Setting::getSetting('allow-attachments')->getValue();

        if(!$allowAttachments && !$forceUpload) return '';
        if(!isset($_FILES['file'])) return '';

        $maxSize = Setting::getSetting('max-size')->getValue();

        $fileUploader = FileUploader::getInstance();
        $fileUploader->setMaxSize($maxSize);

        if($fileUploader->upload('file')) {
            return $fileUploader;
        } else {
            throw new RequestException(ERRORS::INVALID_FILE);
        }
    }

    public function replaceWithImagePaths($imagePaths, $content) {
        if(!is_array($imagePaths)) return $content;
        return str_replace(array_map(function($index) { return "IMAGE_PATH_$index"; }, array_keys($imagePaths)), $imagePaths, $content);
    }

    public static function isLoginMandatory() {
        return Setting::getSetting('mandatory-login')->getValue();
    }

    public static function getCustomFieldValues() {
        $customFields = Customfield::getAll();
        $customFieldValues = new DataStoreList();
        foreach($customFields as $customField) {
            $value = Controller::request('customfield_' . str_replace(' ', '_', $customField->name));
            if($value !== null) {
                $customFieldValue = new Customfieldvalue();
                $customFieldValue->setProperties([
                    'customfield' => $customField,
                ]);

                if($customField->type == 'select') {
                    $ok = false;
                    foreach($customField->ownCustomfieldoptionList as $option) {
                        if($option->name == $value) {
                            $customFieldValue->setProperties([
                                'customfieldoption' => $option,
                                'value' => $option->name,
                            ]);
                            $ok = true;
                        }
                    }
                    if(!$ok)
                        throw new RequestException(ERRORS::INVALID_CUSTOM_FIELD_OPTION);
                } else {
                    $customFieldValue->setProperties([
                        'value' => $value,
                    ]);
                }

                $customFieldValues->add($customFieldValue);
            }
        }
        return $customFieldValues;
    }
}
