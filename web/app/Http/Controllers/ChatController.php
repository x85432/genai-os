<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\Request;
use App\Models\Histories;
use App\Jobs\RequestChat;
use App\Jobs\ImportChat;
use GuzzleHttp\Client;
use App\Models\Chats;
use App\Models\LLMs;
use App\Models\User;
use App\Models\Feedback;
use App\Models\APIHistories;
use DB;
use Session;

class ChatController extends Controller
{
    public function translate(Request $request)
    {
        $record = Histories::find($request->route('history_id'));

        if (!$record) {
            return response('Unauthorized', 401);
        }

        $chat = Chats::find($record->chat_id);

        if (!$chat || $chat->user_id !== Auth::user()->id) {
            return response('Unauthorized', 401);
        }

        $access_code = $request->input('model');
        $msg = $record->msg;
        if ($access_code == null) {
            $access_code = LLMs::find($chat->llm_id)->access_code;
            $msg = "以下提供內容，請幫我翻譯成中文。\n" . $msg;
        }

        $tmp = json_encode([['isbot' => false, 'msg' => $msg]]);
        $history = new APIHistories();
        $history->fill(['input' => $tmp, 'output' => '* ...thinking... *', 'user_id' => Auth::user()->id]);
        $history->save();

        RequestChat::dispatch($tmp, $access_code, Auth::user()->id, -$history->id, Auth::user()->openai_token, 'api_' . $history->id);

        $client = new Client(['timeout' => 300]);
        $req = $client->get(route('api.stream'), [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'query' => [
                'key' => config('app.API_Key'),
                'channel' => 'api_' . $history->id,
            ],
            'stream' => true,
        ]);

        $result = explode('[ENDEDPLACEHOLDERUWU]', $req->getBody()->getContents())[0];

        $history->fill(['output' => $result]);
        $history->save();

        return response($result, 200)->header('Content-Type', 'text/plain');
    }

    public function update_chain(Request $request)
    {
        $state = $request->input('switch') == 'true';
        Session::put('chained', $state);
    }
    public function import(Request $request)
    {
        if (count(Redis::lrange('usertask_' . Auth::user()->id, 0, -1)) == 0) {
            $llm_id = $request->input('llm_id');
            $access_code = LLMs::find($llm_id)->access_code;
            $historys = $request->input('history');
            if ($llm_id && $historys) {
                $result = DB::table(function ($query) {
                    $query
                        ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                        ->from('group_permissions')
                        ->join('permissions', 'perm_id', '=', 'permissions.id')
                        ->where('group_id', Auth()->user()->group_id)
                        ->where('name', 'like', 'model_%')
                        ->get();
                }, 'tmp')
                    ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
                    ->select('llms.id')
                    ->where('llms.enabled', true)
                    ->get()
                    ->pluck('id')
                    ->toarray();
                if (in_array($llm_id, $result)) {
                    $historys = json_decode($historys);
                    if ($historys !== null && isset($historys->messages) && is_array($historys->messages) && count($historys->messages) > 1) {
                        // JSON format
                        $historys = $historys->messages;
                    } else {
                        // TSV format
                        $rows = explode("\n", str_replace("\r\n", "\n", $request->input('history')));
                        $historys = [];
                        $headers = null;

                        foreach ($rows as $index => $row) {
                            if ($index === 0) {
                                $headers = explode("\t", $row);
                                continue;
                            }
                            if ($headers === null) {
                                break;
                            }
                            $columns = explode("\t", $row);

                            $record = [];
                            foreach ($headers as $columnIndex => $header) {
                                if (!isset($columns[$columnIndex]) || empty($columns[$columnIndex])) {
                                    continue;
                                }
                                $value = $columns[$columnIndex];
                                if ($header === 'content') {
                                    $value = trim(json_decode('"' . $value . '"'), '"');
                                }
                                $record[$header] = $value;
                            }
                            $historys[] = (object) $record;
                        }
                    }
                    //Filtering
                    $data = [];
                    $chainValue = false;
                    $flag = false;
                    foreach ($historys as $message) {
                        if ((isset($message->role) && is_string($message->role)) || !isset($message->role)) {
                            if (((isset($message->role) && $message->role === 'user') || !isset($message->role)) && isset($message->content) && is_string($message->content) && trim($message->content) !== '') {
                                if ($flag) {
                                    $newRecord = [
                                        'role' => 'assistant',
                                        'chain' => $chainValue,
                                        'content' => '',
                                    ];
                                    $data[] = (object) $newRecord;
                                }
                                $chainValue = isset($message->chain) ? (bool) $message->chain : false;
                                if (!isset($message->role)) {
                                    $message->role = 'user';
                                }
                                $data[] = $message;
                                $flag = true;
                            } elseif (isset($message->role) && $message->role === 'assistant') {
                                $model = isset($message->model) && is_string($message->model) ? $message->model : $access_code;
                                $content = isset($message->content) && is_string($message->content) ? $message->content : '';

                                if ($model === $access_code) {
                                    $flag = false;
                                    if ($chainValue === true) {
                                        $message->chain = $chainValue;
                                    }
                                    $message->model = $model;
                                    $message->content = $content;
                                    $data[] = $message;
                                }
                            }
                        }
                    }
                    if ($flag) {
                        $newRecord = [
                            'role' => 'assistant',
                            'chain' => $chainValue,
                            'content' => '',
                        ];
                        $data[] = (object) $newRecord;
                    }

                    if ($data) {
                        //Start loading
                        $historys = $data;
                        $first = array_shift($historys);
                        $chat = new Chats();
                        $chatname = $first->content;
                        if (in_array(LLMs::find($llm_id)->access_code, ['doc_qa', 'web_qa', 'doc_qa_b5', 'web_qa_b5'])) {
                            function getWebPageTitle($url)
                            {
                                // Try to fetch the HTML content of the URL
                                $html = @file_get_contents($url);

                                // If the URL is not accessible, return an empty string
                                if ($html === false) {
                                    return '';
                                }

                                // Use regular expressions to extract the title from the HTML
                                if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
                                    return $matches[1];
                                } else {
                                    // If no title is found, return an empty string
                                    return '';
                                }
                            }
                            function getFilenameFromURL($url)
                            {
                                $path_parts = pathinfo($url);

                                if (isset($path_parts['filename'])) {
                                    return $path_parts['filename'];
                                } else {
                                    return '';
                                }
                            }
                            $tmp = getWebPageTitle($first->content);
                            if ($tmp != '') {
                                $chatname = $tmp;
                            } else {
                                $tmp = getWebPageTitle($first->content);
                                if ($tmp != '') {
                                    $chatname = $tmp;
                                }
                            }
                        }
                        $chat->fill(['name' => $chatname, 'llm_id' => $llm_id, 'user_id' => $request->user()->id]);
                        $chat->save();
                        $deltaTime = count($historys);
                        $record = new Histories();
                        $record->fill(['msg' => $first->content, 'chat_id' => $chat->id, 'isbot' => $first->role == 'user' ? false : true, 'chained' => $first->role == 'user' ? false : $first->chained ?? false, 'created_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -' . $deltaTime-- . ' second'))]);
                        $record->save();
                        if (count($historys) > 0) {
                            $user_msg = null;
                            $ids = [];
                            $model_counter = 0;
                            $t = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -' . $deltaTime . ' second'));
                            foreach ($historys as $history) {
                                $history->isbot = $history->role == 'user' ? false : true;
                                if ($history->isbot) {
                                    if ($user_msg != null) {
                                        $record = new Histories();
                                        $record->fill(['msg' => $user_msg, 'chat_id' => $chat->id, 'isbot' => false, 'chained' => $history->chain, 'created_at' => $t, 'updated_at' => $t]);
                                        $record->save();
                                    }
                                    $model_counter += 1;
                                    $t2 = date('Y-m-d H:i:s', strtotime($t . ' +' . $model_counter . ' second'));
                                    $record = new Histories();
                                    $record->fill(['msg' => $history->content == '' ? '* ...thinking... *' : $history->content, 'chat_id' => $chat->id, 'chained' => $history->chain, 'isbot' => true, 'created_at' => $t2, 'updated_at' => $t2]);
                                    $record->save();
                                    if ($history->content == '') {
                                        $ids[] = $record->id;
                                        Redis::rpush('usertask_' . $request->user()->id, $record->id);
                                    }
                                } else {
                                    $user_msg = $history->content;
                                    $t = date('Y-m-d H:i:s', strtotime($t . ' +' . ($model_counter != 0 ? $model_counter : 1) + 1 . ' second'));
                                    $model_counter = 0;
                                }
                            }

                            ImportChat::dispatch($ids, Auth::user()->id);
                        }
                        return Redirect::route('chat.chat', $chat->id);
                    }
                }
            }
        }
        return redirect()->route('chat.home');
    }
    public function home(Request $request)
    {
        $result = DB::table(function ($query) {
            $query
                ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                ->from('group_permissions')
                ->join('permissions', 'perm_id', '=', 'permissions.id')
                ->where('group_id', Auth()->user()->group_id)
                ->where('name', 'like', 'model_%')
                ->get();
        }, 'tmp')
            ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
            ->select('tmp.*', 'llms.*')
            ->where('llms.enabled', true)
            ->orderby('llms.order')
            ->orderby('llms.created_at');
        if ($result->count() == 1 && Auth::user()->hasPerm('Chat_update_new_chat')) {
            return redirect()->route('chat.new', $result->first()->id);
        } else {
            return view('chat');
        }
    }
    public function new_chat(Request $request, $llm_id)
    {
        $result = DB::table(function ($query) {
            $query
                ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                ->from('group_permissions')
                ->join('permissions', 'perm_id', '=', 'permissions.id')
                ->where('group_id', Auth()->user()->group_id)
                ->where('name', 'like', 'model_%')
                ->get();
        }, 'tmp')
            ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
            ->select('llms.id')
            ->where('llms.enabled', true)
            ->get()
            ->pluck('id')
            ->toarray();
        if (!in_array($llm_id, $result) || !LLMs::findOrFail($llm_id)->exists()) {
            return redirect()->route('chat.home');
        }
        if (Auth::user()->hasPerm('Chat_update_new_chat')) {
            return view('chat');
        } else {
            $result = Chats::where('llm_id', '=', $llm_id)->whereNull('dcID')->where("user_id","=",Auth::user()->id);
            if ($result->exists()) {
                return Redirect::route('chat.chat', $result->first()->id);
            } else {
                return view('chat');
            }
        }
    }

    public function upload(Request $request)
    {
        if (count(Redis::lrange('usertask_' . Auth::user()->id, 0, -1)) == 0) {
            $result = DB::table(function ($query) {
                $query
                    ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                    ->from('group_permissions')
                    ->join('permissions', 'perm_id', '=', 'permissions.id')
                    ->where('group_id', Auth()->user()->group_id)
                    ->where('name', 'like', 'model_%')
                    ->get();
            }, 'tmp')
                ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
                ->select('llms.id')
                ->where('llms.enabled', true)
                ->get()
                ->pluck('id')
                ->toarray();
            $llm_id = $request->input('llm_id');

            $request->validate([
                'file' => 'required|max:10240',
            ]);
            if ($llm_id && in_array($llm_id, $result) && $request->file()) {
                // Start uploading the file
                $user = $request->user();
                $userId = $user->id;
                $directory = 'pdfs/' . $userId; // Directory relative to 'public/storage/'
                $storagePath = public_path('storage/' . $directory); // Adjusted path

                $fileName = time() . '_' . $request->file->getClientOriginalName();
                $filePath = $request->file('file')->storeAs($directory, $fileName, 'public'); // Use 'public' disk

                $files = File::files($storagePath);

                if (count($files) > 5) {
                    usort($files, function ($a, $b) {
                        return filectime($a) - filectime($b);
                    });

                    while (count($files) > 5) {
                        $oldestFile = array_shift($files);
                        File::delete($storagePath . '/' . $oldestFile->getFilename());
                    }
                }
                //Create a chat and send that url into the llm
                $msg = url('storage/' . $directory . '/' . rawurlencode($fileName));
                $chat = new Chats();

                $chatname = explode('_', $fileName)[1];
                $chat->fill(['name' => $chatname, 'llm_id' => $llm_id, 'user_id' => $request->user()->id]);
                $chat->save();
                $history = new Histories();
                $history->fill(['msg' => $msg, 'chat_id' => $chat->id, 'isbot' => false]);
                $history->save();
                $history = new Histories();
                $history->fill(['msg' => '* ...thinking... *', 'chat_id' => $chat->id, 'isbot' => true, 'created_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +1 second'))]);
                $history->save();
                Redis::rpush('usertask_' . Auth::user()->id, $history->id);
                RequestChat::dispatch(json_encode([['msg' => $msg, 'isbot' => false]]), LLMs::find($llm_id)->access_code, Auth::user()->id, $history->id, Auth::user()->openai_token);
                return Redirect::route('chat.chat', $chat->id);
            }
        }
        return back();
    }

    public function main(Request $request)
    {
        $chat = Chats::findOrFail($request->route('chat_id'));
        if ($chat->user_id != Auth::user()->id) {
            return redirect()->route('chat.home');
        } elseif (LLMs::findOrFail($chat->llm_id)->enabled == true) {
            return view('chat');
        }
        return redirect()->route('archive.chat', $request->route('chat_id'));
    }

    public function feedback(Request $request)
    {
        $history_id = $request->input('history_id');
        if ($history_id) {
            $nice = $request->input('type') == '1';
            $detail = $request->input('feedbacks');
            $flag = $request->input('feedback');
            $init = $request->input('init');
            $feedback = new Feedback();
            if (Feedback::where('history_id', '=', $history_id)->exists()) {
                $feedback = Feedback::where('history_id', '=', $history_id)->first();
            }
            if ($init) {
                $feedback->fill(['history_id' => $history_id, 'nice' => $nice, 'detail' => null, 'flags' => null]);
            } else {
                $feedback->fill(['history_id' => $history_id, 'nice' => $nice, 'detail' => $detail, 'flags' => $flag == null ? null : json_encode($flag)]);
            }
            $feedback->save();
        }
        return back();
    }

    public function create(ChatRequest $request): RedirectResponse
    {
        if (count(Redis::lrange('usertask_' . Auth::user()->id, 0, -1)) == 0) {
            $result = DB::table(function ($query) {
                $query
                    ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                    ->from('group_permissions')
                    ->join('permissions', 'perm_id', '=', 'permissions.id')
                    ->where('group_id', Auth()->user()->group_id)
                    ->where('name', 'like', 'model_%')
                    ->get();
            }, 'tmp')
                ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
                ->select('llms.id')
                ->where('llms.enabled', true)
                ->get()
                ->pluck('id')
                ->toarray();
            $input = $request->input('input');
            $llm_id = $request->input('llm_id');
            if ($input && $llm_id && in_array($llm_id, $result)) {
                if (in_array(LLMs::find($request->input('llm_id'))->access_code, ['doc_qa', 'web_qa', 'doc_qa_b5', 'web_qa_b5'])) {
                    # Validate first message is exactly URL
                    if (!filter_var($input, FILTER_VALIDATE_URL)) {
                        return back();
                    }
                }
                $chat = new Chats();
                $chatname = $input;
                if (in_array(LLMs::find($request->input('llm_id'))->access_code, ['doc_qa', 'web_qa', 'doc_qa_b5', 'web_qa_b5'])) {
                    function getWebPageTitle($url)
                    {
                        // Try to fetch the HTML content of the URL
                        $html = @file_get_contents($url);

                        // If the URL is not accessible, return an empty string
                        if ($html === false) {
                            return '';
                        }

                        // Use regular expressions to extract the title from the HTML
                        if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
                            return $matches[1];
                        } else {
                            // If no title is found, return an empty string
                            return '';
                        }
                    }
                    function getFilenameFromURL($url)
                    {
                        $path_parts = pathinfo($url);

                        if (isset($path_parts['filename'])) {
                            return $path_parts['filename'];
                        } else {
                            return '';
                        }
                    }
                    $tmp = getWebPageTitle($input);
                    if ($tmp != '') {
                        $chatname = $tmp;
                    } else {
                        $tmp = getWebPageTitle($input);
                        if ($tmp != '') {
                            $chatname = $tmp;
                        }
                    }
                }
                $chat->fill(['name' => mb_substr($chatname, 0, 75, 'utf-8'), 'llm_id' => $llm_id, 'user_id' => $request->user()->id]);
                $chat->save();
                $history = new Histories();
                $history->fill(['msg' => $input, 'chat_id' => $chat->id, 'isbot' => false]);
                $history->save();
                $tmp = Histories::where('chat_id', '=', $chat->id)
                    ->select('msg', 'isbot')
                    ->get()
                    ->toJson();
                $history = new Histories();
                $history->fill(['msg' => '* ...thinking... *', 'chat_id' => $chat->id, 'isbot' => true, 'created_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +1 second'))]);
                $history->save();
                $llm = LLMs::findOrFail($llm_id);
                Redis::rpush('usertask_' . Auth::user()->id, $history->id);
                RequestChat::dispatch($tmp, $llm->access_code, Auth::user()->id, $history->id, Auth::user()->openai_token);
                return Redirect::route('chat.chat', $chat->id);
            }
        } else {
            Log::channel('analyze')->info('User ' . Auth::user()->id . ' with ' . implode(',', Redis::lrange('usertask_' . Auth::user()->id, 0, -1)));
        }
        return back();
    }

    public function request(Request $request): RedirectResponse
    {
        if (count(Redis::lrange('usertask_' . Auth::user()->id, 0, -1)) == 0) {
            $result = DB::table(function ($query) {
                $query
                    ->select(DB::raw('substring(name, 7) as model_id'), 'perm_id')
                    ->from('group_permissions')
                    ->join('permissions', 'perm_id', '=', 'permissions.id')
                    ->where('group_id', Auth()->user()->group_id)
                    ->where('name', 'like', 'model_%')
                    ->get();
            }, 'tmp')
                ->join('llms', 'llms.id', '=', DB::raw('CAST(tmp.model_id AS BIGINT)'))
                ->select('llms.id')
                ->where('llms.enabled', true)
                ->get()
                ->pluck('id')
                ->toarray();
            $chatId = $request->input('chat_id');
            $llm_id = Chats::find($request->input('chat_id'));
            if ($llm_id) {
                $llm_id = $llm_id->llm_id;
            }
            $input = $request->input('input');
            $chained = Session::get('chained') == 'true';
            if ($chatId && $input && $llm_id && in_array($llm_id, $result)) {
                $history = new Histories();
                $history->fill(['msg' => $input, 'chat_id' => $chatId, 'isbot' => false]);
                $history->save();
                if (in_array(LLMs::find(Chats::find($request->input('chat_id'))->llm_id)->access_code, ['doc_qa', 'web_qa', 'doc_qa_b5', 'web_qa_b5']) && !$chained) {
                    $tmp = json_encode([
                        [
                            'msg' => Histories::where('chat_id', '=', $chatId)
                                ->select('msg')
                                ->orderby('created_at')
                                ->orderby('id', 'desc')
                                ->get()
                                ->first()->msg,
                            'isbot' => false,
                        ],
                        ['msg' => $request->input('input'), 'isbot' => false],
                    ]);
                } elseif ($chained) {
                    $tmp = Histories::where('chat_id', '=', $chatId)
                        ->select('msg', 'isbot')
                        ->orderby('created_at')
                        ->orderby('id', 'desc')
                        ->get()
                        ->toJson();
                } else {
                    $tmp = json_encode([['msg' => $request->input('input'), 'isbot' => false]]);
                }
                $history = new Histories();
                $history->fill(['chained' => $chained, 'msg' => '* ...thinking... *', 'chat_id' => $chatId, 'isbot' => true, 'created_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +1 second'))]);
                $history->save();
                $access_code = LLMs::findOrFail(Chats::findOrFail($chatId)->llm_id)->access_code;
                Redis::rpush('usertask_' . Auth::user()->id, $history->id);
                RequestChat::dispatch($tmp, $access_code, Auth::user()->id, $history->id, Auth::user()->openai_token);
                return Redirect::route('chat.chat', $chatId);
            }
        }
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        try {
            $chat = Chats::findOrFail($request->input('id'));
        } catch (ModelNotFoundException $e) {
            // Handle the exception here, for example:
            return Redirect::route('chat.home');
        }

        Histories::where('chat_id', '=', $chat->id)->delete();
        $chat->delete();
        if (Auth::user()->hasPerm('Chat_update_new_chat')) {
            return redirect()->route('chat.new', $chat->llm_id);
        }
        return Redirect::route('chat.home');
    }

    public function edit(Request $request): RedirectResponse
    {
        try {
            $chat = Chats::findOrFail($request->input('id'));
        } catch (ModelNotFoundException $e) {
            // Handle the exception here, for example:
            return response()->json(['error' => 'Chat not found'], 404);
        }
        $chat->fill(['name' => $request->input('new_name')]);
        $chat->save();
        return Redirect::route('chat.chat', $request->input('id'));
    }

    public function SSE(Request $request)
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('charset', 'utf-8');
        $response->headers->set('Connection', 'close');

        $response->setCallback(function () use ($response, $request) {
            $channel = $request->input('channel');
            if ($channel != null && strpos($channel, 'aielection_') === 0) {
                $client = Redis::connection();
                $client->subscribe($channel, function ($message, $raw_history_id) use ($client, $response) {
                    [$type, $msg] = explode(' ', $message, 2);
                    if ($type == 'Ended') {
                        echo "event: close\n\n";
                        ob_flush();
                        flush();
                        $client->disconnect();
                    } elseif ($type == 'New') {
                        echo 'data: ' . json_encode(['msg' => json_decode($msg)->msg]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                });
            } else {
                global $listening;
                $listening = Redis::lrange('usertask_' . Auth::user()->id, 0, -1);
                if (count($listening) > 0) {
                    foreach ($listening as $i) {
                        $history = Histories::find($i);
                        if (!$history) {
                            unset($listening[array_search($i, $listening)]);
                        } elseif ($history && $history->msg !== '* ...thinking... *') {
                            echo 'data: ' . json_encode(['history_id' => $i, 'msg' => $history->msg]) . "\n\n";
                            ob_flush();
                            flush();
                            unset($listening[array_search($i, $listening)]);
                        }
                    }
                    if (count($listening) == 0) {
                        echo "data: finished\n\n";
                        echo "event: close\n\n";
                        ob_flush();
                        flush();
                        $client->disconnect();
                    }

                    $client = Redis::connection();
                    try {
                        $client->subscribe($listening, function ($message, $raw_history_id) use ($client, $response) {
                            global $listening;
                            [$type, $msg] = explode(' ', $message, 2);
                            $history_id = substr($raw_history_id, strrpos($raw_history_id, '_') + 1);
                            if ($type == 'Ended') {
                                $key = array_search($history_id, $listening);
                                if ($key !== false) {
                                    unset($listening[$key]);
                                }
                                if (count($listening) == 0) {
                                    echo "data: finished\n\n";
                                    echo "event: close\n\n";
                                    ob_flush();
                                    flush();
                                    $client->disconnect();
                                }
                            } elseif ($type == 'New') {
                                echo 'data: ' . json_encode(['history_id' => $history_id, 'msg' => json_decode($msg)->msg]) . "\n\n";
                                ob_flush();
                                flush();
                            }
                        });
                    } catch (RedisException) {
                    }
                } else {
                    echo "data: finished\n\n";
                    echo "event: close\n\n";
                    ob_flush();
                    flush();
                    try {
                        if ($client) {
                            $client->disconnect();
                        }
                    } catch (Exception) {
                    }
                }
            }
        });

        return $response;
    }
}
