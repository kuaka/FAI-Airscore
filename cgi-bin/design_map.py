"""
Map definition
Creates map from track GeoJSON and Task Definition JSON
Use: design_map <traPk> <tasPk> <test>

Martino Boni - 2019
"""


import os,sys

#from flask import Flask, flash, request, redirect, url_for, session, json
#from flask import get_template_attribute,render_template
import folium
from compUtils import read_formula
from trackUtils import get_pil_track
from pprint import pprint

import itertools

#using aerofiles library to parse igc to geojson
#from aerofiles.igc import Reader

#database connections
from myconn import Database

#use Task to get task definition
from task import Task

#use Track to get track from DB
from track import Track

### select the library to parse the igc to geojson
global IGC_LIB
IGC_LIB = 'igc_lib'

# app = Flask(__name__)
# app.secret_key = "A Super super uper secret key"
# app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER


##############################################################################################################

# functions to style geojson geometries
def style_function(feature):
    return {'fillColor': 'white','weight': 2,'opacity': 1,'color': 'red','fillOpacity': 0.5,"stroke-width":3}

def style_function_prestart(feature):
    return {'fillColor': 'white','weight': 2,'opacity': 1, 'color': 'gray','fillOpacity': 0.5,"stroke-width":3}

# function to return the bbox of geometries
def checkbbox(lat,lon,bbox):
    if lat < bbox[0][0]:
        bbox[0][0] = lat
    if lon < bbox[0][1]:
        bbox[0][1] = lon
    if lat > bbox[1][0]:
        bbox[1][0] = lat
    if lon > bbox[1][1]:
        bbox[1][1] = lon

    return bbox

def get_bbox(flight):
    """Gets track boundaries """

    assert flight.valid

    #TODO write objects to the geojson form the flight object
    min_lat = flight.fixes[0].lat
    min_lon = flight.fixes[0].lon
    max_lat = flight.fixes[0].lat
    max_lon = flight.fixes[0].lon

    bbox = [[min_lat,min_lon],[max_lat,max_lon]]

    for fix in flight.fixes:
        bbox = checkbbox(fix.lat,fix.lon,bbox)
    return bbox

def get_route_bbox(task):
    """Gets task boundaries """

    #TODO write objects to the geojson form the flight object
    min_lat = task.optimised_turnpoints[0].lat
    min_lon = task.optimised_turnpoints[0].lon
    max_lat = task.optimised_turnpoints[0].lat
    max_lon = task.optimised_turnpoints[0].lon

    bbox = [[min_lat,min_lon],[max_lat,max_lon]]

    for fix in task.optimised_turnpoints:
        bbox = checkbbox(fix.lat,fix.lon,bbox)
    return bbox

def get_region_bbox(region):
    """Gets region map boundaries """

    wpts = region.turnpoints
    #TODO write objects to the geojson form the flight object
    min_lat = wpts[0].lat
    min_lon = wpts[0].lon
    max_lat = wpts[0].lat
    max_lon = wpts[0].lon

    bbox = [[min_lat,min_lon],[max_lat,max_lon]]

    for wpt in wpts:
        bbox = checkbbox(wpt.lat,wpt.lon,bbox)
    return bbox

# function to create the map template with optional geojson, circles and points objects
def make_map(layer_geojson=False,points=False,circles=False,polyline=False,margin=0):
    folium_map = folium.Map(location=[45.922207, 8.673952],zoom_start=13,tiles="Stamen Terrain",width='100%',height='75%')

    '''Define map borders'''
    if layer_geojson["bbox"]:
        bbox = layer_geojson["bbox"]
        folium_map.fit_bounds(bounds=bbox,max_zoom=13)

    """Design track"""
    if layer_geojson["geojson"]:
        geojson = layer_geojson["geojson"]
        folium.GeoJson(geojson,name='Flight',style_function=style_function).add_to(folium_map)
#        folium.GeoJson(geojson,name='Flight',style_function=style_function,tooltip=folium.features.GeoJsonTooltip(labels=True,sticky=False)).add_to(folium_map)

    if layer_geojson["prestart"]:
        geojson = layer_geojson["prestart"]
        folium.GeoJson(geojson,name='preFlight',style_function=style_function_prestart).add_to(folium_map)
        
    """Design cylinders"""
    if circles:
        for c in circles:
            """create design based on type"""
            if c['type'] == 'launch':
                col = '#996633'
            elif c['type'] == 'speed':
                col = '#00cc00'
            elif c['type'] == 'endspeed':
                col = '#cc3333'
            elif c['type'] == 'restricted':
                col = '#ff0000'
            else:
                col = '#3186cc'

            folium.Circle(
                location    = [c['latitude'], c['longitude']],
                radius      = 0.0 + c['radius'],
                popup       = 'Wpt: '+c['name']+'<br>Radius: '+str(c['radius'])+'m.',
                color       = col,
                weight      = 2,
                opacity     = 0.8,
                fill        = True,
                fill_opacity= 0.2,
                fill_color  = col
                ).add_to(folium_map)

    """Plot tolerance cylinders"""
    if margin:
        for c in circles:
            """create two circles based on tolerance value"""
            folium.Circle(
                location    = [c['latitude'], c['longitude']],
                radius      = 0.0 + c['radius']*(1+margin),
                popup       = 'Wpt: '+c['name']+'<br>Radius: '+str(c['radius'])+'m.',
                color       = "#44cc44",
                weight      = 0.75,
                opacity     = 0.8,
                fill        = False
                ).add_to(folium_map)

            folium.Circle(
                location    = [c['latitude'], c['longitude']],
                radius      = 0.0 + c['radius']*(1-margin),
                popup       = 'Wpt: '+c['name']+'<br>Radius: '+str(c['radius'])+'m.',
                color       = "#44cc44",
                weight      = 0.75,
                opacity     = 0.8,
                fill        = False
                ).add_to(folium_map)

    """Plot waypoints"""
    if points:
        for p in points:
            folium.Marker(
                location    = [p['latitude'], p['longitude']],
                popup       = p['name'],
                icon        = folium.features.DivIcon(
                                icon_size   = (20,20),
                                icon_anchor = (0,0),
                                html='<div class="waypoint-label">%s</div>' % p['name'],
                                )
                ).add_to(folium_map)

    """Design optimised route"""
    if polyline:
        folium.PolyLine(
            locations   = polyline,
            weight      = 1.5,
            opacity     = 0.75,
            color       = '#2176bc'
            ).add_to(folium_map)

    #path where to save the map
    #folium_map.save('templates/map.html')
    return folium_map

# function to extract flight details from flight object and return formatted html
def extract_flight_details(flight):
    flight_html = ""
    flight_html+= "Flight:" + str(flight) +'\n'
    flight_html+= "Takeoff:" + str(flight.takeoff_fix) +'\n'
    zipped = itertools.izip_longest(flight.thermals, flight.glides)
    for x, (thermal, glide) in enumerate(zipped):
        if glide:
            flight_html+= "  glide[" + str(x) + "]:" + str(glide) + '\n'
        if thermal:
            flight_html+= "  thermal[" + str(x) + "]:" + str(thermal) + '\n'
    flight_html+= "Landing:" + str(flight.landing_fix)

    return flight_html

# dump flight object to geojson
def dump_flight(track, task):
    geojson_file_prestart = track.to_geojson(maxtime=task.start_time)
    geojson_file_poststart = track.to_geojson(mintime=task.start_time)
    bbox = get_bbox(track.flight)

    return geojson_file_prestart, geojson_file_poststart, bbox

# allowed uploads
# def allowed_file(filename):
#     return '.' in filename and \
#            filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

# function to parse task object to compilations
def get_task(task):
    task_coords = []
    turnpoints = []
    short_route = []

    for obj in task.turnpoints:
        task_coords.append({
        'longitude' : obj.lon,
        'latitude'  : obj.lat,
        'name'      : obj.name
        })

        turnpoints.append({
        'radius'    : obj.radius,
        'longitude' : obj.lon,
        'latitude'  : obj.lat,
#         'altitude': obj.altitude,
        'name'      : obj.name,
        'type'      : obj.type,
        'shape'     : obj.shape
        })

    for obj in task.optimised_turnpoints:
        short_route.append(tuple([obj.lat,obj.lon]))
        #print ("short route fix: {}, {}".format(obj.lon,obj.lat))

    return task_coords,turnpoints,short_route

def get_region(region_id):
    from region import Region as Reg

    region = Reg.read_db(region_id)
    wpt_coords = []
    turnpoints = []

    for obj in region.turnpoints:
        wpt_coords.append({
        'longitude' : obj.lon,
        'latitude'  : obj.lat,
        'name'      : obj.name
        })

        turnpoints.append({
        'radius'    : obj.radius,
        'longitude' : obj.lon,
        'latitude'  : obj.lat,
#         'altitude': obj.altitude,
        'name'      : obj.name,
        'type'      : obj.type,
        'shape'     : obj.shape

        })

    return region, wpt_coords,turnpoints

def main(mode, val, track_id):
    """Main module"""
#     log_dir = d.LOGDIR
#     print("log setup")
#     logging.basicConfig(filename=log_dir + 'main.log',level=logging.INFO,format='%(asctime)s %(message)s')
    #test = 0
    #pilot_id = 0
    #task_id = 0
    wpt_coords = []
    turnpoints = []
    short_route = []
    tolerance = 0
    map = None
    layer={}
    #map_file = '../map.html'

    if mode == 'region':
        '''waypoints map'''
        region_id = val
        region, wpt_coords, turnpoints = get_region(region_id)
        layer['geojson'] = None
        layer['prestart'] = None
        layer['bbox'] = get_region_bbox(region)
    else:
        '''create the task map for route or tracklog maps'''
        task_id = val
        task = Task.read_task(task_id)
        formula = read_formula(task.comPk)
        tolerance = 0.000000 + formula['forMargin']/100
        wpt_coords, turnpoints, short_route = get_task(task)
        if mode == 'tracklog':
            """create task and track objects"""
            track = Track.read_db(track_id)
            layer['prestart'], layer['geojson'], layer['bbox'] = dump_flight(track)
        elif mode == 'route':
            layer['geojson'] = None
            layer['prestart'] = None
            layer['bbox'] = get_route_bbox(task)

    #flight_results = extract_flight_details(track.flight) #at the moment we have no takoff_fix, landing_fix or thermal in Flight Obj

    map = make_map(layer_geojson=layer, points=wpt_coords, circles=turnpoints, polyline=short_route, margin=tolerance)
    #map.save(map_file)
    #os.chown(map_file, 1000, 1000)
    html_string = map.get_root().render()

    # if test:
    #     print("starting..")
    #     print("TaskID: {} - CompID: {}".format(task.tasPk, task.comPk))
    #     print("pil ID: {} - task ID: {} - track ID: {}".format(pilot_id, task_id, track_id))
    #     print ("Tolerance: {} ".format(tolerance))
    #     pprint(html_string)

    #test for srcdoc iframe source
    print(html_string)


if __name__== "__main__":
    track_id    = None
    val         = None
    mode        = None
    ##check parameter is good.
    if len(sys.argv) >= 3 and sys.argv[2].isdigit():
        mode    = str(sys.argv[1])
        val     = int(sys.argv[2])
        if mode == 'tracklog': track_id = int(sys.argv[3])

    else:
        #logging.error("number of arguments != 1 and/or task_id not a number")
        print("Error, uncorrect arguments type or number.")
        print("usage: design_map pilPk tasPk <test>")
        exit()
    main(mode, val, track_id)
