#include <amxmodx>
#include <amxmisc>
#include <fakemeta>
#include <message_const>

#define PLUGIN  "GameLand LAN Optimizer"
#define VERSION "1.3.0"
#define AUTHOR  "GAMELAND"

#define TASK_APPLY   41001
#define TASK_CLEANUP 41002
#define TASK_CLIENTS 41003
#define MAX_PLAYERS  32

new g_pcvar_enable
new g_pcvar_rates
new g_pcvar_cleanup
new g_pcvar_profiles
new g_pcvar_debug
new g_pcvar_cleanup_interval
new g_pcvar_client_interval
new g_pcvar_weaponbox_limit
new g_pcvar_grenade_limit
new g_pcvar_drop_age
new g_pcvar_adaptive
new g_pcvar_decals
new g_pcvar_tempfx
new g_pcvar_waterfx
new g_pcvar_impactfx
new g_pcvar_smokefx
new g_pcvar_lightfx
new g_pcvar_gibfx
new g_pcvar_tracerfx
new g_pcvar_force_clients
new g_pcvar_clean_armoury

new g_lastCleanupRemoved
new g_lastCleanupSeen
new g_lastCleanupSkipped
new g_lastWeaponboxLimit
new g_lastGrenadeLimit
new g_lastFxBlocked
new g_lastProfile[32]

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)
    register_cvar("gameland_lan_optimizer_version", VERSION, FCVAR_SERVER | FCVAR_SPONLY)

    g_pcvar_enable = register_cvar("gl_lan_optimizer", "1")
    g_pcvar_rates = register_cvar("gl_lan_rates", "1")
    g_pcvar_cleanup = register_cvar("gl_lan_cleanup", "1")
    g_pcvar_profiles = register_cvar("gl_lan_map_profiles", "1")
    g_pcvar_debug = register_cvar("gl_lan_debug", "0")
    g_pcvar_cleanup_interval = register_cvar("gl_lan_cleanup_interval", "18.0")
    g_pcvar_client_interval = register_cvar("gl_lan_client_interval", "45.0")
    g_pcvar_weaponbox_limit = register_cvar("gl_lan_weaponbox_limit", "18")
    g_pcvar_grenade_limit = register_cvar("gl_lan_grenade_limit", "12")
    g_pcvar_drop_age = register_cvar("gl_lan_drop_age", "8.0")
    g_pcvar_adaptive = register_cvar("gl_lan_adaptive", "1")
    g_pcvar_decals = register_cvar("gl_lan_decals", "96")
    g_pcvar_tempfx = register_cvar("gl_lan_tempfx_filter", "1")
    g_pcvar_waterfx = register_cvar("gl_lan_suppress_waterfx", "1")
    g_pcvar_impactfx = register_cvar("gl_lan_suppress_impactfx", "1")
    g_pcvar_smokefx = register_cvar("gl_lan_suppress_smokefx", "1")
    g_pcvar_lightfx = register_cvar("gl_lan_suppress_lightfx", "1")
    g_pcvar_gibfx = register_cvar("gl_lan_suppress_gibfx", "1")
    g_pcvar_tracerfx = register_cvar("gl_lan_suppress_tracerfx", "0")
    g_pcvar_force_clients = register_cvar("gl_lan_force_client_rates", "0")
    g_pcvar_clean_armoury = register_cvar("gl_lan_clean_armoury", "0")

    register_concmd("gl_lan_status", "cmd_status", ADMIN_ALL, "- shows LAN optimizer status")
    register_concmd("gl_lan_optimize", "cmd_optimize", ADMIN_ALL, "- reapplies LAN host settings")
    register_concmd("gl_lan_cleanup_now", "cmd_cleanup_now", ADMIN_ALL, "- runs safe cleanup")
    register_message(SVC_TEMPENTITY, "message_tempentity")

    set_task(3.0, "task_apply_settings", TASK_APPLY)
    schedule_cleanup_task()
    schedule_client_task()
}

public plugin_cfg()
{
    task_apply_settings()
    apply_map_profile()
    get_adaptive_limits(g_lastWeaponboxLimit, g_lastGrenadeLimit)
}

public client_putinserver(id)
{
    if (!is_valid_player(id) || is_user_bot(id))
        return

    if (get_pcvar_num(g_pcvar_force_clients))
        set_task(4.0, "task_apply_client", id)
}

public message_tempentity(msgid, dest, id)
{
    if (!optimizer_enabled() || !get_pcvar_num(g_pcvar_tempfx))
        return PLUGIN_CONTINUE

    new type = get_msg_arg_int(1)

    if (get_pcvar_num(g_pcvar_waterfx) && (type == TE_BUBBLES || type == TE_BUBBLETRAIL || type == TE_FIZZ || type == TE_LAVASPLASH))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    if (get_pcvar_num(g_pcvar_impactfx) && (type == TE_GUNSHOT || type == TE_GUNSHOTDECAL || type == TE_MULTIGUNSHOT || type == TE_DECAL || type == TE_DECALHIGH || type == TE_WORLDDECAL || type == TE_WORLDDECALHIGH || type == TE_BSPDECAL || type == TE_SPARKS || type == TE_ARMOR_RICOCHET || type == TE_STREAK_SPLASH))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    if (get_pcvar_num(g_pcvar_smokefx) && (type == TE_SMOKE || type == TE_SPRITE_SPRAY || type == TE_SPRAY || type == TE_PARTICLEBURST || type == TE_FIREFIELD))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    if (get_pcvar_num(g_pcvar_lightfx) && (type == TE_DLIGHT || type == TE_ELIGHT || type == TE_GLOWSPRITE))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    if (get_pcvar_num(g_pcvar_gibfx) && (type == TE_BLOODSTREAM || type == TE_BLOOD || type == TE_BLOODSPRITE || type == TE_MODEL || type == TE_EXPLODEMODEL || type == TE_BREAKMODEL))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    if (get_pcvar_num(g_pcvar_tracerfx) && (type == TE_TRACER || type == TE_USERTRACER))
    {
        g_lastFxBlocked++
        return PLUGIN_HANDLED
    }

    return PLUGIN_CONTINUE
}

public cmd_optimize(id, level, cid)
{
    if (!cmd_access(id, level, cid, 1))
        return PLUGIN_HANDLED

    task_apply_settings()
    apply_map_profile()
    console_print(id, "[GL LAN] Optimizer settings reapplied.")
    return PLUGIN_HANDLED
}

public cmd_cleanup_now(id, level, cid)
{
    if (!cmd_access(id, level, cid, 1))
        return PLUGIN_HANDLED

    task_cleanup()
    console_print(id, "[GL LAN] Cleanup finished. seen=%d removed=%d", g_lastCleanupSeen, g_lastCleanupRemoved)
    return PLUGIN_HANDLED
}

public cmd_status(id, level, cid)
{
    if (!cmd_access(id, level, cid, 1))
        return PLUGIN_HANDLED

    new map[32]
    get_mapname(map, charsmax(map))

    console_print(id, "[GL LAN] %s v%s", PLUGIN, VERSION)
    console_print(id, "[GL LAN] map=%s profile=%s players=%d/%d dedicated=%d", map, g_lastProfile, get_playersnum(), get_maxplayers(), is_dedicated_server())
    console_print(id, "[GL LAN] enabled=%d rates=%d cleanup=%d profiles=%d force_clients=%d",
        get_pcvar_num(g_pcvar_enable),
        get_pcvar_num(g_pcvar_rates),
        get_pcvar_num(g_pcvar_cleanup),
        get_pcvar_num(g_pcvar_profiles),
        get_pcvar_num(g_pcvar_force_clients))
    console_print(id, "[GL LAN] limits: weaponbox=%d grenade=%d clean_armoury=%d",
        g_lastWeaponboxLimit,
        g_lastGrenadeLimit,
        get_pcvar_num(g_pcvar_clean_armoury))
    console_print(id, "[GL LAN] adaptive=%d drop_age=%.1f decals=%d", get_pcvar_num(g_pcvar_adaptive), get_pcvar_float(g_pcvar_drop_age), get_pcvar_num(g_pcvar_decals))
    console_print(id, "[GL LAN] last_cleanup: seen=%d removed=%d skipped=%d", g_lastCleanupSeen, g_lastCleanupRemoved, g_lastCleanupSkipped)
    console_print(id, "[GL LAN] tempfx=%d waterfx=%d impactfx=%d blocked=%d",
        get_pcvar_num(g_pcvar_tempfx),
        get_pcvar_num(g_pcvar_waterfx),
        get_pcvar_num(g_pcvar_impactfx),
        g_lastFxBlocked)
    console_print(id, "[GL LAN] fx flags: smoke=%d light=%d gib=%d tracer=%d",
        get_pcvar_num(g_pcvar_smokefx),
        get_pcvar_num(g_pcvar_lightfx),
        get_pcvar_num(g_pcvar_gibfx),
        get_pcvar_num(g_pcvar_tracerfx))
    return PLUGIN_HANDLED
}

public task_apply_settings()
{
    if (!optimizer_enabled())
        return

    if (get_pcvar_num(g_pcvar_rates))
        apply_host_rates()

    apply_map_profile()
}

public task_cleanup()
{
    if (!optimizer_enabled() || !get_pcvar_num(g_pcvar_cleanup))
    {
        schedule_cleanup_task()
        return
    }

    g_lastCleanupSeen = 0
    g_lastCleanupRemoved = 0
    g_lastCleanupSkipped = 0

    get_adaptive_limits(g_lastWeaponboxLimit, g_lastGrenadeLimit)

    cleanup_class_limited("weaponbox", g_lastWeaponboxLimit)
    if (get_pcvar_num(g_pcvar_clean_armoury))
        cleanup_class_limited("armoury_entity", g_lastWeaponboxLimit)
    cleanup_class_limited("grenade", g_lastGrenadeLimit)

    if (get_pcvar_num(g_pcvar_debug))
        server_print("[GL LAN] cleanup profile=%s seen=%d removed=%d skipped=%d wb_limit=%d gr_limit=%d", g_lastProfile, g_lastCleanupSeen, g_lastCleanupRemoved, g_lastCleanupSkipped, g_lastWeaponboxLimit, g_lastGrenadeLimit)

    schedule_cleanup_task()
}

public task_clients()
{
    if (optimizer_enabled() && get_pcvar_num(g_pcvar_rates) && get_pcvar_num(g_pcvar_force_clients))
    {
        for (new id = 1; id <= MAX_PLAYERS; id++)
        {
            if (is_user_connected(id) && !is_user_bot(id))
                apply_client_rates(id)
        }
    }

    schedule_client_task()
}

public task_apply_client(id)
{
    if (optimizer_enabled() && get_pcvar_num(g_pcvar_rates) && get_pcvar_num(g_pcvar_force_clients) && is_user_connected(id) && !is_user_bot(id))
        apply_client_rates(id)
}

stock bool:optimizer_enabled()
{
    return bool:get_pcvar_num(g_pcvar_enable)
}

stock bool:is_valid_player(id)
{
    return (id >= 1 && id <= MAX_PLAYERS)
}

stock schedule_cleanup_task()
{
    new Float:interval = get_pcvar_float(g_pcvar_cleanup_interval)
    if (interval < 10.0)
        interval = 10.0

    remove_task(TASK_CLEANUP)
    set_task(interval, "task_cleanup", TASK_CLEANUP)
}

stock schedule_client_task()
{
    new Float:interval = get_pcvar_float(g_pcvar_client_interval)
    if (interval < 20.0)
        interval = 20.0

    remove_task(TASK_CLIENTS)
    set_task(interval, "task_clients", TASK_CLIENTS)
}

stock apply_host_rates()
{
    new decals = get_pcvar_num(g_pcvar_decals)
    if (decals < 32)
        decals = 32
    if (decals > 160)
        decals = 160

    server_cmd("sv_lan 1")
    server_cmd("sv_maxrate 100000")
    server_cmd("sv_minrate 25000")
    server_cmd("sv_maxupdaterate 101")
    server_cmd("sv_minupdaterate 30")
    server_cmd("sv_timeout 65")
    server_cmd("pausable 0")
    server_cmd("mp_logdetail 0")
    server_cmd("mp_logmessages 0")
    server_cmd("mp_logfile 0")
    server_cmd("mp_decals %d", decals)
    server_cmd("sv_wateramp 0")
    server_cmd("decalfrequency 60")
    server_exec()
}

stock apply_client_rates(id)
{
    client_cmd(id, "rate 100000")
    client_cmd(id, "cl_cmdrate 101")
    client_cmd(id, "cl_updaterate 101")
    client_cmd(id, "ex_interp 0.01")
}

stock apply_map_profile()
{
    g_lastProfile[0] = 0

    if (!get_pcvar_num(g_pcvar_profiles))
    {
        copy(g_lastProfile, charsmax(g_lastProfile), "manual")
        return
    }

    new map[32]
    get_mapname(map, charsmax(map))

    if (is_fast_small_map(map))
    {
        copy(g_lastProfile, charsmax(g_lastProfile), "fast-small")
        set_pcvar_float(g_pcvar_cleanup_interval, 10.0)
        set_pcvar_num(g_pcvar_weaponbox_limit, 10)
        set_pcvar_num(g_pcvar_grenade_limit, 6)
        set_pcvar_float(g_pcvar_drop_age, 4.0)
        set_pcvar_num(g_pcvar_decals, 64)
        set_pcvar_num(g_pcvar_impactfx, 1)
        set_pcvar_num(g_pcvar_waterfx, 1)
        set_pcvar_num(g_pcvar_smokefx, 1)
        set_pcvar_num(g_pcvar_lightfx, 1)
        set_pcvar_num(g_pcvar_gibfx, 1)
        server_cmd("mp_decals 64")
        server_exec()
        return
    }

    if (is_heavy_map(map))
    {
        copy(g_lastProfile, charsmax(g_lastProfile), "heavy")
        set_pcvar_float(g_pcvar_cleanup_interval, 12.0)
        set_pcvar_num(g_pcvar_weaponbox_limit, 12)
        set_pcvar_num(g_pcvar_grenade_limit, 8)
        set_pcvar_float(g_pcvar_drop_age, 6.0)
        set_pcvar_num(g_pcvar_decals, 32)
        set_pcvar_num(g_pcvar_impactfx, 1)
        set_pcvar_num(g_pcvar_waterfx, 1)
        set_pcvar_num(g_pcvar_smokefx, 1)
        set_pcvar_num(g_pcvar_lightfx, 1)
        set_pcvar_num(g_pcvar_gibfx, 1)
        server_cmd("mp_decals 32")
        server_cmd("sv_wateramp 0")
        server_exec()
        return
    }

    if (is_competitive_map(map))
    {
        copy(g_lastProfile, charsmax(g_lastProfile), "competitive")
        set_pcvar_float(g_pcvar_cleanup_interval, 14.0)
        set_pcvar_num(g_pcvar_weaponbox_limit, 16)
        set_pcvar_num(g_pcvar_grenade_limit, 10)
        set_pcvar_float(g_pcvar_drop_age, 7.0)
        set_pcvar_num(g_pcvar_decals, 64)
        set_pcvar_num(g_pcvar_impactfx, 1)
        set_pcvar_num(g_pcvar_waterfx, 1)
        set_pcvar_num(g_pcvar_smokefx, 1)
        set_pcvar_num(g_pcvar_lightfx, 1)
        set_pcvar_num(g_pcvar_gibfx, 1)
        server_cmd("mp_decals 64")
        server_exec()
        return
    }

    copy(g_lastProfile, charsmax(g_lastProfile), "universal")
    set_pcvar_float(g_pcvar_cleanup_interval, 16.0)
    set_pcvar_num(g_pcvar_weaponbox_limit, 18)
    set_pcvar_num(g_pcvar_grenade_limit, 12)
    set_pcvar_float(g_pcvar_drop_age, 8.0)
    set_pcvar_num(g_pcvar_decals, 64)
    set_pcvar_num(g_pcvar_impactfx, 1)
    set_pcvar_num(g_pcvar_waterfx, 1)
    set_pcvar_num(g_pcvar_smokefx, 1)
    set_pcvar_num(g_pcvar_lightfx, 1)
    set_pcvar_num(g_pcvar_gibfx, 1)
    server_cmd("mp_decals 64")
    server_exec()
}

stock cleanup_class_limited(const classname[], limit)
{
    if (limit < 0)
        limit = 0

    new ent = -1
    new seen = 0

    while ((ent = engfunc(EngFunc_FindEntityByString, ent, "classname", classname)) > 0)
    {
        if (!pev_valid(ent))
            continue

        seen++
        g_lastCleanupSeen++

        if (seen <= limit)
        {
            g_lastCleanupSkipped++
            continue
        }

        if (safe_remove_entity(ent, classname))
            g_lastCleanupRemoved++
        else
            g_lastCleanupSkipped++
    }
}

stock bool:safe_remove_entity(ent, const classname[])
{
    if (!pev_valid(ent))
        return false

    new Float:created
    pev(ent, pev_fuser4, created)
    if (created <= 0.0)
    {
        set_pev(ent, pev_fuser4, get_gametime())
        return false
    }

    if ((get_gametime() - created) < get_pcvar_float(g_pcvar_drop_age))
        return false

    new owner = pev(ent, pev_owner)
    new aiment = pev(ent, pev_aiment)

    if (is_valid_player(owner) || is_valid_player(aiment))
        return false

    if (equal(classname, "grenade"))
    {
        new Float:dmgtime
        pev(ent, pev_dmgtime, dmgtime)
        if (dmgtime > get_gametime())
            return false
    }

    engfunc(EngFunc_RemoveEntity, ent)
    return true
}

stock get_adaptive_limits(&weaponboxLimit, &grenadeLimit)
{
    weaponboxLimit = get_pcvar_num(g_pcvar_weaponbox_limit)
    grenadeLimit = get_pcvar_num(g_pcvar_grenade_limit)

    if (!get_pcvar_num(g_pcvar_adaptive))
        return

    new players = get_playersnum()
    if (players >= 10)
    {
        weaponboxLimit -= 4
        grenadeLimit -= 3
    }
    else if (players >= 6)
    {
        weaponboxLimit -= 2
        grenadeLimit -= 1
    }
    else if (players <= 2)
    {
        weaponboxLimit += 4
        grenadeLimit += 2
    }

    if (weaponboxLimit < 6)
        weaponboxLimit = 6
    if (grenadeLimit < 4)
        grenadeLimit = 4
}

stock bool:is_fast_small_map(const map[])
{
    return (containi(map, "aim_") == 0 ||
        containi(map, "fy_") == 0 ||
        containi(map, "awp_") == 0 ||
        containi(map, "35hp") != -1 ||
        containi(map, "he_") == 0)
}

stock bool:is_heavy_map(const map[])
{
    return (containi(map, "aztec") != -1 ||
        containi(map, "chateau") != -1 ||
        containi(map, "piranesi") != -1 ||
        containi(map, "storm") != -1 ||
        containi(map, "survivor") != -1 ||
        containi(map, "torn") != -1 ||
        containi(map, "747") != -1 ||
        containi(map, "siege") != -1 ||
        containi(map, "estate") != -1 ||
        containi(map, "militia") != -1)
}

stock bool:is_competitive_map(const map[])
{
    return (containi(map, "de_") == 0 ||
        containi(map, "cs_") == 0 ||
        containi(map, "dust") != -1 ||
        containi(map, "inferno") != -1 ||
        containi(map, "nuke") != -1 ||
        containi(map, "train") != -1 ||
        containi(map, "cbble") != -1)
}
