#include <amxmodx>
#include <reapi>

#define PLUGIN  "GAMELAND AllClient Permanent Enforcer"
#define VERSION "3.5"
#define AUTHOR  "GAMELAND"

#define ALLCLIENT_SIGNATURE "GL_PERMANENT_VERIFIED_ALLCLIENT_2026"
#define ALLCLIENT_TOKEN     "GAMELAND_ALLCLIENT_PRO_2026"

new bool:g_isVerified[33];

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR);
    server_print("[GAMELAND] ================================================================");
    server_print("[GAMELAND] AllClient Permanent Enforcer v3.5 LOADED SUCCESSFULLY!");
    server_print("[GAMELAND] Strictly enforcing official GAMELAND AllClient engine signature.");
    server_print("[GAMELAND] ================================================================");
}

public client_putinserver(id)
{
    g_isVerified[id] = false;

    if (is_user_bot(id) || is_user_hltv(id))
    {
        g_isVerified[id] = true;
        return;
    }

    // Step 1: Early secondary check: verify UserInfo token
    new token[64];
    get_user_info(id, "_gltoken", token, charsmax(token));
    if (equal(token, ALLCLIENT_TOKEN))
    {
        g_isVerified[id] = true;
        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED] Player '%s' pre-authenticated via UserInfo token.", name);
    }

    // Step 2: Query client cvar after 0.25s delay ensuring client message buffer is receptive
    remove_task(id);
    set_task(0.25, "TaskQueryClientCvar", id);

    // Step 3: Strict 4.0s timeout enforcement task
    set_task(4.0, "EnforceTimeoutDrop", id);
}

public TaskQueryClientCvar(id)
{
    if (is_user_connected(id) && !is_user_bot(id) && !is_user_hltv(id))
    {
        query_client_cvar(id, "gl_allclient_signature", "OnCvarSignatureResult");
    }
}

public client_disconnected(id)
{
    g_isVerified[id] = false;
    remove_task(id);
}

// AMX Mod X query_client_cvar callback (must have exactly 4 arguments)
public OnCvarSignatureResult(id, const cvar[], const value[], const param[])
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    // Check if the client returned the permanent engine signature OR protected status (from AllClient build)
    if (equal(value, ALLCLIENT_SIGNATURE) || equal(value, "CVAR is protected"))
    {
        g_isVerified[id] = true;
        remove_task(id);

        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED] Player '%s' successfully authenticated via AllClient engine signature ('%s').", name, value);
    }
    else
    {
        // Value is "Bad CVAR request", empty, or wrong -> Generic NextClient or unauthorized client
        ExecuteDrop(id, "Cvar Signature Namotabar (Generic NextClient/Steam/Non-Steam)");
    }
}

public EnforceTimeoutDrop(id)
{
    if (!is_user_connected(id) || is_user_bot(id) || is_user_hltv(id))
        return;

    if (g_isVerified[id])
        return;

    // Fallback secondary check: verify UserInfo token
    new token[64];
    get_user_info(id, "_gltoken", token, charsmax(token));

    if (equal(token, ALLCLIENT_TOKEN))
    {
        g_isVerified[id] = true;
        new name[32];
        get_user_name(id, name, charsmax(name));
        server_print("[GAMELAND] [VERIFIED] Player '%s' authenticated via UserInfo token fallback.", name);
        return;
    }

    ExecuteDrop(id, "Timeout / Adam-e Ehraz-e Hoviat (Unauthorized Client)");
}

stock ExecuteDrop(id, const reason[])
{
    new name[32], ip[32], userid = get_user_userid(id);
    get_user_name(id, name, charsmax(name));
    get_user_ip(id, ip, charsmax(ip), 1);

    server_print("[GAMELAND] [KICK] Unauthorized client '%s' (%s, #%d) dropped! Reason: %s", name, ip, userid, reason);
    
    // Instant drop at network level using ReAPI rh_drop_client
    rh_drop_client(id, "^n[GAMELAND] Faghat AllClient Ekhtesasi mojaz ast!^nDownload: gameland.cam");
}
