#include <amxmodx>
#include <fakemeta>

#define PLUGIN  "GameLand C4 Pro HUD Timer"
#define VERSION "4.1.0"
#define AUTHOR  "GAMELAND"

#define TASK_C4_TICK 52001
#define MAX_CLIENTS  32

new g_pcvar_enable
new g_pcvar_dhud
new g_pcvar_pos_y
new g_pcvar_debug

new g_msgHideWeapon
new g_msgStatusIcon
new g_syncHudTimer

new Float:g_c4ExplodeTime
new bool:g_bombPlanted = false
new bool:g_hudHidden[MAX_CLIENTS + 1] = {false, ...}

// HIDEHUD_TIMER flag in GoldSrc CS 1.6 is (1<<4) = 16
#define HIDEHUD_TIMER (1<<4)

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)
    register_cvar("gameland_c4_timer_version", VERSION, FCVAR_SERVER | FCVAR_SPONLY)

    g_pcvar_enable = register_cvar("gl_c4_hud_timer", "1")
    g_pcvar_dhud   = register_cvar("gl_c4_timer_dhud", "1")       // 1 = Large director HUD (exact size of clock digits), 0 = Classic HUD
    g_pcvar_pos_y  = register_cvar("gl_c4_timer_pos_y", "0.932")   // Adjusted lower to align exactly with the native HUD clock row
    g_pcvar_debug  = register_cvar("gl_c4_timer_debug", "0")

    g_msgHideWeapon = get_user_msgid("HideWeapon")
    g_msgStatusIcon = get_user_msgid("StatusIcon")

    g_syncHudTimer = CreateHudSyncObj()

    // 1. Logevent plant
    register_logevent("logevent_c4_planted", 3, "2=Planted_The_Bomb")

    // 2. BarTime planting progress
    register_event("BarTime", "event_bartime", "be", "1=3")

    // 3. Stop events
    register_logevent("logevent_round_end", 2, "1=Round_End")
    register_event("HLTV", "event_round_start", "a", "1=0", "2=0")
    register_event("SendAudio", "event_bomb_defused", "a", "2=%!MRAD_BOMBDEF")
    register_event("SendAudio", "event_target_bombed", "a", "2=%!MRAD_TARGETBOM")
    register_event("ResetHUD", "event_resethud", "b")
}

public client_disconnected(id)
{
    g_hudHidden[id] = false
}

public event_resethud(id)
{
    if (g_bombPlanted && get_pcvar_num(g_pcvar_enable))
    {
        hide_player_timer(id, true)
    }
    else
    {
        hide_player_timer(id, false)
    }
}

public event_round_start()
{
    stop_c4_timer()
}

public logevent_round_end()
{
    stop_c4_timer()
}

public event_bomb_defused()
{
    stop_c4_timer()
}

public event_target_bombed()
{
    stop_c4_timer()
}

public event_bartime(id)
{
    if (get_user_team(id) == 1 && !g_bombPlanted)
    {
        set_task(3.05, "task_check_plant_fallback")
    }
}

public task_check_plant_fallback()
{
    if (!g_bombPlanted)
    {
        new ent = -1
        while ((ent = engfunc(EngFunc_FindEntityByString, ent, "classname", "grenade")) > 0)
        {
            new model[32]
            pev(ent, pev_model, model, charsmax(model))
            if (containi(model, "w_c4.mdl") != -1)
            {
                start_c4_timer()
                break
            }
        }
    }
}

public logevent_c4_planted()
{
    start_c4_timer()
}

stock start_c4_timer()
{
    if (!get_pcvar_num(g_pcvar_enable) || g_bombPlanted)
        return

    new c4cvar = get_cvar_num("mp_c4timer")
    if (c4cvar < 10)
        c4cvar = 35

    g_bombPlanted = true
    g_c4ExplodeTime = get_gametime() + float(c4cvar)

    // 1. Immediately hide native round timer for all players
    for (new i = 1; i <= MAX_CLIENTS; i++)
    {
        if (is_user_connected(i))
        {
            hide_player_timer(i, true)
            // Clear any lingering left-screen StatusIcon
            clear_status_icon(i)
        }
    }

    // 2. Start the precision tick (0.05s for smooth response and fluid pulse)
    remove_task(TASK_C4_TICK)
    set_task(0.05, "task_c4_tick", TASK_C4_TICK, _, _, "b")

    if (get_pcvar_num(g_pcvar_debug))
        server_print("[GL C4 Pro] C4 Timer started: %d seconds.", c4cvar)
}

public task_c4_tick()
{
    if (!g_bombPlanted)
    {
        remove_task(TASK_C4_TICK)
        return
    }

    new Float:remaining = g_c4ExplodeTime - get_gametime()
    if (remaining <= 0.0)
    {
        stop_c4_timer()
        return
    }

    new sec = floatround(remaining, floatround_ceil)
    new minutes = sec / 60
    new seconds = sec % 60

    new r, g, b
    static flash_state = 0

    if (remaining <= 4.0)
    {
        // Intense heartbeat pulse in the final 4 seconds
        flash_state = !flash_state
        if (flash_state)
        {
            r = 255
            g = 20
            b = 20
        }
        else
        {
            r = 160
            g = 0
            b = 0
        }
    }
    else if (remaining <= 10.0)
    {
        // Warning Orange
        r = 255
        g = 130
        b = 0
    }
    else
    {
        // Authentic CS 1.6 Gold/Amber HUD Color
        r = 255
        g = 190
        b = 20
    }

    new Float:posY = get_pcvar_float(g_pcvar_pos_y)

    if (get_pcvar_num(g_pcvar_dhud))
    {
        // DHUD font is significantly larger (exact size of the native GoldSrc clock digits)
        set_dhudmessage(r, g, b, -1.0, posY, 0, 0.0, 0.12, 0.0, 0.0)
        show_dhudmessage(0, "[C4] %d:%02d", minutes, seconds)
    }
    else
    {
        // Classic HUD font
        set_hudmessage(r, g, b, -1.0, posY, 0, 0.0, 0.12, 0.0, 0.0, 1)
        ShowSyncHudMsg(0, g_syncHudTimer, "[C4]  %d:%02d", minutes, seconds)
    }
}

stock hide_player_timer(id, bool:hide)
{
    if (!is_user_connected(id))
        return

    g_hudHidden[id] = hide

    message_begin(MSG_ONE_UNRELIABLE, g_msgHideWeapon, _, id)
    write_byte(hide ? HIDEHUD_TIMER : 0)
    message_end()
}

stock clear_status_icon(id)
{
    // Cleanly remove any leftover C4 status icon from the left equipment panel
    message_begin(MSG_ONE_UNRELIABLE, g_msgStatusIcon, _, id)
    write_byte(0)
    write_string("c4")
    write_byte(0)
    write_byte(0)
    write_byte(0)
    message_end()
}

stock stop_c4_timer()
{
    if (g_bombPlanted)
    {
        g_bombPlanted = false
        remove_task(TASK_C4_TICK)

        // Clear HUD sync message
        ClearSyncHud(0, g_syncHudTimer)

        // Restore native timer for all connected players
        for (new i = 1; i <= MAX_CLIENTS; i++)
        {
            if (is_user_connected(i))
            {
                hide_player_timer(i, false)
                clear_status_icon(i)
            }
        }

        if (get_pcvar_num(g_pcvar_debug))
            server_print("[GL C4 Pro] C4 Timer stopped and HUD restored.")
    }
}
