<?php
class ControllerGroup {
    private $groupPath;
    private $controllers = array();

    public function setGroupPath($groupPath) {
        $this->groupPath = $groupPath;
    }

    public function addController($controller) {
        array_push($this->controllers, $controller);
    }

    public function finalize() {
        $app = Controller::getAppInstance();
        $controllers = $this->controllers;

        $app->group($this->groupPath, function (\Slim\Routing\RouteCollectorProxy $group) use ($controllers) {
            foreach ($controllers as $controller) {
                if($controller::METHOD === 'POST') {
                    $group->post($controller::PATH, $controller->getHandler());
                } else {
                    $group->get($controller::PATH, $controller->getHandler());
                }
            }
        });
    }
}
