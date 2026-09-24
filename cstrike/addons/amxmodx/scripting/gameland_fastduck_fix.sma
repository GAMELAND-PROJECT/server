#include <amxmodx>
#include <fakemeta>
#include <hamsandwich>

#define PLUGIN  "GameLand Fast-Duck & View Shake Fixer"
#define VERSION "2.0.0"
#define AUTHOR  "GAMELAND"

#define MAX_PLAYERS 32

// CS 1.6 Player Duck Offsets (CBasePlayer)
// m_flDuckTime = 363 (linux diff +5 = 368)
// m_bInDuck = 364
#define OFFSET_DUCKTIME     363
#define EXTRAOFFSET_PLAYER  5

// Weapon FOV offset in CBasePlayer: m_iFOV = 363 or pev_fov
// When zooming with AWP, Scout, SG550, G3SG1, pev_fov is < 90 (e.g. 40 or 10)
#define DEFAULT_FOV 90

new g_pcvar_enable
new g_pcvar_debug

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)
    register_cvar("gameland_fastduck_fix_version", VERSION, FCVAR_SERVER | FCVAR_SPONLY)

    g_pcvar_enable = register_cvar("gl_fastduck_fix", "1")
    g_pcvar_debug  = register_cvar("gl_fastduck_debug", "0")

    RegisterHam(Ham_Player_PreThink, "player", "ham_player_prethink", 0)
}

public ham_player_prethink(id)
{
    if (!get_pcvar_num(g_pcvar_enable) || !is_user_alive(id) || is_user_bot(id))
        return HAM_IGNORED

    // CRITICAL FIX: If player is holding a sniper rifle (AWP, Scout, G3SG1, SG550) or aiming/shooting,
    // NEVER touch duck offsets or movement delays!
    // In CS 1.6, quickscoping (fast-zoom) and duck-aiming rely on precise engine attack/zoom frame timings.
    new clip, ammo
    new weapon = get_user_weapon(id, clip, ammo)
    if (weapon == CSW_AWP || weapon == CSW_SCOUT || weapon == CSW_G3SG1 || weapon == CSW_SG550)
        return HAM_IGNORED

    new button = pev(id, pev_button)
    new oldbuttons = pev(id, pev_oldbuttons)

    // Also ignore if player is currently firing or using secondary attack (zooming/scoping)
    if ((button & IN_ATTACK) || (button & IN_ATTACK2) || (oldbuttons & IN_ATTACK) || (oldbuttons & IN_ATTACK2))
        return HAM_IGNORED

    new flags = pev(id, pev_flags)

    // Player released duck key while on ground
    if ((oldbuttons & IN_DUCK) && !(button & IN_DUCK) && (flags & FL_ONGROUND))
    {
        // When tapping duck (Fast Duck / Double Duck), the engine introduces a 1000ms delay penalty in m_flDuckTime
        new Float:ducktime = get_pdata_float(id, OFFSET_DUCKTIME, EXTRAOFFSET_PLAYER)
        if (ducktime > 0.0)
        {
            // Reset the delay penalty immediately to keep the movement fluid and synchronized with hitbox
            set_pdata_float(id, OFFSET_DUCKTIME, 0.0, EXTRAOFFSET_PLAYER)

            if (get_pcvar_num(g_pcvar_debug))
                server_print("[GL DUCK] Cleared duck penalty for player %d", id)
        }
    }

    return HAM_IGNORED
}
