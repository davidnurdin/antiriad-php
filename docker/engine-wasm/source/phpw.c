// JumPHP WebAssembly entry point.
//
// phpw_run(code) boots the embedded PHP, evaluates `code` (the page prelude + engine/bootstrap/wasm.php),
// then hands the PHP function jumphp_frame() to emscripten_set_main_loop(): the browser calls it once per
// requestAnimationFrame. PHP is never shut down: objects and SDL resources live between frames.
#include "sapi/embed/php_embed.h"
#include <emscripten.h>
#include <stdlib.h>

#include "zend_globals_macros.h"
#include "zend_exceptions.h"

static zval frame_name, frame_retval;
static zend_fcall_info frame_fci;
static zend_fcall_info_cache frame_fcc;
static int frame_ready = 0;

static void main_loop(void)
{
    if (!frame_ready) return;

    zend_try {
        if (zend_call_function(&frame_fci, &frame_fcc) == SUCCESS) {
            zval_ptr_dtor(&frame_retval);
        } else {
            fprintf(stderr, "[jumphp] jumphp_frame() call failed\n");
        }
        if (EG(exception)) {
            zend_exception_error(EG(exception), E_WARNING);
            zend_clear_exception();
        }
    } zend_catch {
        fprintf(stderr, "[jumphp] fatal error in jumphp_frame()\n");
    } zend_end_try();
}

int main(void)
{
    return 0;
}

void EMSCRIPTEN_KEEPALIVE phpw_run(char *code)
{
    php_embed_init(0, NULL);
    zend_try {
        zend_eval_string(code, NULL, "jumphp boot");

        if (EG(exception)) {
            zend_exception_error(EG(exception), E_ERROR);
        } else {
            ZVAL_STRING(&frame_name, "jumphp_frame");
            if (zend_fcall_info_init(&frame_name, 0, &frame_fci, &frame_fcc, NULL, NULL) != SUCCESS) {
                fprintf(stderr, "[jumphp] jumphp_frame() is not defined: the boot script failed\n");
            } else {
                frame_fci.param_count = 0;
                frame_fci.params = NULL;
                frame_fci.retval = &frame_retval;
                frame_ready = 1;
                // one call per requestAnimationFrame; throws "unwind" to JS, which is the normal case
                emscripten_set_main_loop(main_loop, 0, 1);
            }
        }
    } zend_catch {
    } zend_end_try();

    php_embed_shutdown();
}
