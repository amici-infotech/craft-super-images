<?php
/**
 * Signed runtime generation endpoint.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

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
 * Runtime Controller
 *
 * Handles anonymous signed lazy-generation requests and redirects to the output URL.
 */
class RuntimeController extends Controller
{
    /**
     * CSRF validation is disabled because requests are authenticated via signed query params.
     *
     * @var bool
     */
    public $enableCsrfValidation = false;

    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * Verifies a signed request, generates the derivative, and redirects to its URL.
     *
     * @return Response Redirect to the generated image URL, or a raw error response.
     */
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

    /**
     * Builds a plain-text error response with the given status code.
     *
     * @param int $statusCode HTTP status code.
     * @param string $message Error message body.
     *
     * @return Response Raw error response.
     */
    private function errorResponse(int $statusCode, string $message): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode($statusCode);
        $response->format = Response::FORMAT_RAW;
        $response->content = $message;

        return $response;
    }
}
