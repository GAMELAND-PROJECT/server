#include <amxmodx>
#include <reapi>
#include <next_client_api>

#define PLUGIN  "GAMELAND AllClient Permanent Enforcer"
#define VERSION "3.0"
#define AUTHOR  "GAMELAND"

#define ALLCLIENT_SIGNATURE "GL_PERMANENT_VERIFIED_ALLCLIENT_2026"
#define ALLCLIENT_TOKEN     "GAMELAND_ALLCLIENT_PRO_2026"

new bool:g_isVerified[33];

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR);
    server_print("[GAMELAND] AllClient Permanent Enforcer v3.0 loaded! Using permanent engine signature authentication.");
}

public client_putinserver(id)
{
    g_isVerified[id] = false;

    if (is_user_bot(id) || is_user_hltv(id))
    {
        g_isVerified[id] = true;
        return;
    }

    // Immediately trigger permanent engine cvar query
    query_client_cvar(id, "gl_allclient_signature", "OnCvarSignatureResult");

    // Timeout safety task: 1.5 seconds maximum to complete authentication
    remove_task(id);
    set_task(1.5, "EnforceClientAuth", id);
}

public client_disconnected(id)
{
    g_isVerified[id] = false;
    remove_task(id);
}

public OnCvarSignatureResult(id, const cvar[], const value[])
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    // Verify the permanent engine signature
    if (equal(value, ALLCLIENT_SIGNATURE))
    {
        g_isVerified[id] = true;
        remove_task(id);

        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED-PERMANENT] Player '%s' authenticated successfully as official GAMELAND AllClient.", name);
    }
    else
    {
        ExecuteDrop(id, "Cvar Signature Namotabar (Generic NextClient/Modified)");
    }
}

public EnforceClientAuth(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    if (g_isVerified[id])
        return;

    // Fallback secondary check: UserInfo token
    new token[64];
    get_user_info(id, "_gltoken", token, charsmax(token));

    if (equal(token, ALLCLIENT_TOKEN))
    {
        g_isVerified[id] = true;
        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED-TOKEN] Player '%s' authenticated via UserInfo token.", name);
        return;
    }

    ExecuteDrop(id, "Adam-e Ehraz-e Hoviat (Unauthorized Client)");
}

stock ExecuteDrop(id, const reason[])
{
    new name[32], ip[32], userid = get_user_userid(id);
    get_user_name(id, name, charsmax(name));
    get_user_ip(id, ip, charsmax(ip), 1);

    server_print("[GAMELAND] [REJECTED] Unauthorized connection '%s' (%s, #%d) dropped! Reason: %s", name, ip, userid, reason);
    
    // Instant drop at network level using ReAPI rh_drop_client
    rh_drop_client(id, "^n[GAMELAND] Faghat AllClient Ekhtesasi mojaz ast!^nDownload: gameland.cam");
}
