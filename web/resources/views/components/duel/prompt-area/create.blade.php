@props(['llms'])
<form method="post"
    action="{{ route('duel.create') . (request()->input('limit') > 0 ? '' : '?limit=' . request()->input('limit')) }}"
    id="prompt_area">
    <div class="flex flex-col items-end justify-end">
        @csrf
        @foreach (session('llms') as $id)
            <input name="llm[]" value="{{ $id }}" style="display:none;">
            <input id="chatsTo_{{ $id }}" name="chatsTo[]" value="{{ $id }}" style="display:none;">
        @endforeach

        <div class="flex mr-auto dark:text-white mb-2 select-none">
            <div>
                <div class="flex justify-center items-center">{{ __('Send to:') }}
                    @foreach ($llms as $llm)
                        <span data-tooltip-target="llm_{{ $llm->id }}_toggle" data-tooltip-placement="top"
                            id="btn_{{ $llm->id }}_toggle"
                            onclick="$('#chatsTo_{{ $llm->id }}').prop('disabled',(i,val)=>{return !val}); $(this).toggleClass('bg-green-500 hover:bg-green-600 bg-red-500 hover:bg-red-600')"
                            class="cursor-pointer flex py-1 px-2 mx-1 bg-green-500 hover:bg-green-600 rounded-full">
                            <div
                                class="inline h-5 w-5 rounded-full border border-gray-400 dark:border-gray-900 bg-black overflow-hidden">
                                <img
                                    src="{{ strpos($llm->image, 'data:image/png;base64') === 0 ? $llm->image : asset(Storage::url($llm->image)) }}">
                            </div>
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <textarea tabindex="0" data-id="root" placeholder="{{ __('Send a message') }}" rows="1" max-rows="5"
            oninput="adjustTextareaRows(this)" id="chat_input" name="input" readonly
            class="w-full pl-4 pr-12 py-2 rounded text-black scrollbar dark:text-white placeholder-black dark:placeholder-white bg-gray-200 dark:bg-gray-600 border border-gray-300 focus:outline-none shadow-none border-none focus:ring-0 focus:border-transparent rounded-l-md resize-none"></textarea>
        <button type="submit" id='submit_msg' style='display:none;'
            class="inline-flex items-center justify-center fixed w-[32px] bg-blue-600 h-[32px] my-[4px] mr-[12px] rounded hover:bg-blue-500 dark:hover:bg-blue-700">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none"
                class="w-5 h-5 text-white dark:text-gray-300 icon-sm m-1 md:m-0">
                <path
                    d="M.5 1.163A1 1 0 0 1 1.97.28l12.868 6.837a1 1 0 0 1 0 1.766L1.969 15.72A1 1 0 0 1 .5 14.836V10.33a1 1 0 0 1 .816-.983L8.5 8 1.316 6.653A1 1 0 0 1 .5 5.67V1.163Z"
                    fill="currentColor"></path>
            </svg>
        </button>
    </div>
    <input type="hidden" name="limit" value="{{ request()->input('limit') > 0 ? request()->input('limit') : '0' }}">
</form>
<x-duel.prompt-area.chat-script :llms="$llms"/>
