<?php

class ChatController extends Controller
{
    private mixed $userId;


    public function __construct(string $pageType = 'api')
    {
        // check if user is authenticated
        $userModel = $this->model('UserModel');
        $this->userId = $userModel->get_user_if_logged_in();
        if($this->userId === false) {
            if ($pageType === 'api') {
                http_response_code(401);
                echo "401 Unauthorized";
            } else {
                header('Location: /');
            }
            exit();

        }


        // check if user role and capability allows for access to this page
        /*$has_current_page_access = $userModel->has_current_page_access();
        if($has_current_page_access === false) {
            http_response_code(403);
            $this->view('error-403');
        }*/

    }

    public function index(): void
    {
        //xTODO: check what is required to load
        // Page data
        $pageData = [
            'pageTitle'     => 'Chat',
            'cssFiles'      => [
                '/assets/css/bootstrap.min.css',
                '/assets/css/icons.min.css',
                '/assets/css/app.min.css',
                '/assets/css/custom.min.css',
                '/assets/libs/glightbox/css/glightbox.min.css'
            ],
            'jsHeaderFiles' => [
                '/assets/js/layout.js'
            ],
            'jsFooterFiles' => [
                '/assets/libs/bootstrap/js/bootstrap.bundle.min.js',
                '/assets/libs/simplebar/simplebar.min.js',
                '/assets/libs/node-waves/waves.min.js',
                '/assets/libs/feather-icons/feather.min.js',
                '/assets/js/pages/plugins/lord-icon-2.1.0.js',
                '/assets/js/plugins.js',
                '/assets/js/app.js',
                '/assets/libs/glightbox/js/glightbox.min.js',
                '/assets/js/pages/chat.init.js',
                '/assets/libs/fg-emoji-picker/fgEmojiPicker.js'
            ]
        ];

        // show view
        $this->view('chat', [ 'pageData' => $pageData ]);
    }

    // Events endpoint
    public function getEvents($reqQuery) {

        $lastUpdated = isset($reqQuery['last_updated']) ? $reqQuery['last_updated'] : null;

        $response = [
            'success' => true,
            'current_timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            $ChatModel = $this->model('ChatModel');
            if (!$lastUpdated) {
                $response['data'] = $ChatModel->getInitialData($this->userId['id']);
            } else {
                $response['data'] = $ChatModel->getIncrementalUpdates($this->userId['id'], $lastUpdated);
            }
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    // Channel operations
    public function createChannel(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $channelId = $ChatModel->createChannel(
                $this->userId['id'],
                $data['name'],
                $data['description'] ?? '',
                (int) $data['is_public']
            );
            $response['data'] = ['channel_id' => $channelId];
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function listChannels(): void
    {

        $response = ['success' => true];

        try {

            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->getChannels($this->userId['id']);
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function inviteToChannel(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->inviteToChannel(
                $this->userId['id'],
                $data['channel_id'],
                $data['user_id']
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function joinChannel(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->joinChannel($this->userId['id'], $data['channel_id']);
            $response['message'] = 'Joined channel successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function leaveChannel(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->leaveChannel($this->userId['id'], $data['channel_id']);
            $response['message'] = 'Left channel successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    // User operations
    public function searchUsers($reqQuery): void
    {

        $response = ['success' => true];


        try {

            $ChatModel = $this->model('ChatModel');

            if (!isset($reqQuery['q']) || strlen(trim($reqQuery['q'])) < 3) {
                throw new Exception('Search query must be at least 3 characters long.', 400);
            }

            $page = isset($reqQuery['page']) ? (int)$reqQuery['page'] : 1;
            $limit = isset($reqQuery['limit']) ? (int)$reqQuery['limit'] : 20;

            $response['data'] = $ChatModel->searchUsers(
                $reqQuery['q'],
                $page,
                $limit
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function getUserData($reqQuery): void
    {

        if (!isset($reqQuery['user_id'])) {
            throw new Exception("User ID is required", 400);
        }

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->getUserData(
                $this->userId['id'],
                $reqQuery['user_id']
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);
    }

    public function blockUser(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->blockUser($this->userId['id'], $data['user_id']);
            $response['message'] = 'User blocked successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function unblockUser(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->unblockUser($this->userId['id'], $data['user_id']);
            $response['message'] = 'User unblocked successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);
    }

    // Contact operations
    public function listContacts(): void
    {

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->listContacts($this->userId['id']);
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function addContact(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->addContact($this->userId['id'], $data['contact_id']);
            $response['message'] = 'Contact added successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function deleteContact(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->deleteContact($this->userId['id'], $data['contact_id']);
            $response['message'] = 'Contact removed successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    // Conversation operations
    public function listConversations(): void
    {

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->listConversations($this->userId['id']);
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function createConversation(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->createConversation(
                $this->userId['id'],
                $data['user_id']
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);
    }

    public function getMessages($reqQuery): void
    {

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');

            if (!isset($reqQuery['chat_id']) || !isset($reqQuery['target'])) {
                throw new Exception("Missing required parameters", 400);
            }

            if (!in_array($reqQuery['target'], ['chat', 'channel'])) {
                throw new Exception("Invalid target type", 400);
            }

            $page = isset($reqQuery['page']) ? (int)$reqQuery['page'] : 1;
            $limit = isset($reqQuery['limit']) ? (int)$reqQuery['limit'] : 50;
            $before = isset($reqQuery['before']) ? $reqQuery['before'] : null;
            $after = isset($reqQuery['after']) ? $reqQuery['after'] : null;

            $response['data'] = $ChatModel->getMessages(
                $reqQuery['chat_id'],
                $reqQuery['target'],
                $this->userId['id'],
                $page,
                $limit,
                $before,
                $after
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function sendMessage(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->sendMessage(
                $this->userId['id'],
                $data['conversation_id'],
                $data['content'],
                $data['message_type'],
                $data['parent_message_id'] ?? null,
                $data['target'] ?? 'chat'
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    // Message operations
    public function updateMessageStatus(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->updateMessageStatus(
                $this->userId['id'],
                $data['message_id'],
                $data['status']
            );
            $response['message'] = 'Message status updated successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function toggleBookmark(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->toggleBookmark($this->userId['id'], $data['message_id']);
            $response['message'] = 'Bookmark toggled successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function deleteMessage(): void
    {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->deleteMessage($this->userId['id'], $data['message_id']);
            $response['message'] = 'Message deleted successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function forwardMessage() {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $response['data'] = $ChatModel->forwardMessage(
                $this->userId['id'],
                $data['message_id'],
                $data['conversation_id']
            );
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    // Report operations
    public function reportMessage() {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->reportMessage(
                $this->userId['id'],
                $data['message_id'],
                $data['reason']
            );
            $response['message'] = 'Message reported successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

    public function reportUser() {

        $data = json_decode(file_get_contents('php://input'), true);

        $response = ['success' => true];

        try {
            $ChatModel = $this->model('ChatModel');
            $ChatModel->reportUser(
                $this->userId['id'],
                $data['user_id'],
                $data['reason']
            );
            $response['message'] = 'User reported successfully';
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'error' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                ]
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response);

    }

}
