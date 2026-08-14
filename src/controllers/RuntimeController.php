<?php

namespace amici\SuperImages\controllers;

use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\Plugin;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Signed runtime generation endpoint.
 */
class RuntimeController extends Controller
{
    public $enableCsrfValidation = false;

    protected array|bool|int $allowAnonymous = true;

    public function actionGenerate(): Response
    {
        $plugin = Plugin::getInstance();

        try {
            if (!$plugin->getSettings()->enabled) {
                throw new ForbiddenHttpException('Super Images is disabled.');
            }

            $params = $plugin->getSignedUrls()->verify(Craft::$app->getRequest()->getQueryParams());
            $url = $plugin->getRuntimeGeneration()->handle($params);

            return $this->redirect($url, 302);
        } catch (ForbiddenHttpException $exception) {
            return $this->errorResponse(403, $exception->getMessage());
        } catch (SuperImagesException $exception) {
            return $this->errorResponse(400, $exception->getMessage());
        } catch (BadRequestHttpException $exception) {
            return $this->errorResponse(400, $exception->getMessage());
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->errorResponse(500, 'Image generation failed.');
        }
    }

    private function errorResponse(int $statusCode, string $message): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode($statusCode);
        $response->format = Response::FORMAT_RAW;
        $response->content = $message;

        return $response;
    }
}
