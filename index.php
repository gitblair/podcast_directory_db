
<?php
// get a taddy api user and api key
// create a config.php in this style:
// $taddy_user = 'xxxxx';
// $taddy_api_key = 'xxxxx';

require "config.php";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set the character set to utf8mb4
$conn->set_charset("utf8mb4");

// Fetch RSS feed function
function fetch_rss_feed($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $rss = curl_exec($ch);
    if ($rss === false) {
        error_log('Error fetching RSS feed: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $rss;
}

// Parse RSS feed function
function parse_rss_feed($rss, $limit = 3) {
    if ($rss === false) {
        return ['error' => 'Failed to fetch RSS feed.'];
    }

    $xml = @simplexml_load_string($rss);
    if ($xml === false) {
        error_log('Error parsing RSS feed.');
        return ['error' => 'Failed to parse RSS feed.'];
    }

    $channel = $xml->channel;
    $podcast_info = [
        'title' => isset($channel->title) ? (string)$channel->title : '',
        'description' => isset($channel->description) ? (string)$channel->description : '',
        'imageUrl' => isset($channel->image->url) ? (string)$channel->image->url : ''
    ];

    $episodes = [];
    $count = 0;
    foreach ($channel->item as $item) {
        if ($count >= $limit) {
            break;
        }
        $episode = [
            'title' => isset($item->title) ? (string)$item->title : '',
            'description' => isset($item->description) ? (string)$item->description : '',
            'pub_date' => isset($item->pubDate) ? (string)$item->pubDate : '',
            'audio_url' => isset($item->enclosure['url']) ? (string)$item->enclosure['url'] : ''
        ];
        $episodes[] = $episode;
        $count++;
    }

    return ['podcast_info' => $podcast_info, 'episodes' => $episodes];
}

// Fetch podcast metadata from database
$result = $conn->query("SELECT name, itunesId, description, imageUrl, rssUrl FROM taddypodcasts");
$podcasts = [];
while ($row = $result->fetch_assoc()) {
    $podcasts[] = $row;
}

// Determine selected RSS URL
$selected_rssUrl = $_GET['rssUrl'] ?? null;
if ($selected_rssUrl === null && !empty($podcasts)) {
    $selected_rssUrl = $podcasts[0]['rssUrl'];
}

$podcast_info = ['title' => 'No Podcast Selected', 'description' => 'Please select a podcast to load episodes.'];
$episodes = [];
$latest_episodes_cards = [];
$latest_episodes_table = [];

if ($selected_rssUrl !== null) {
    $rss_content = fetch_rss_feed($selected_rssUrl);
    if ($rss_content !== false) {
        // Fetch latest episodes (limit to 3)
        $podcast_data = parse_rss_feed($rss_content, 3);
        if (!isset($podcast_data['error'])) {
            $podcast_info = $podcast_data['podcast_info'];
            $episodes = $podcast_data['episodes'];
        } else {
            die($podcast_data['error']);
        }

        // Fetch latest episodes for table (limit to 10)
        $podcast_data_table = parse_rss_feed($rss_content, 10);
        if (!isset($podcast_data_table['error'])) {
            $latest_episodes_table = $podcast_data_table['episodes'];
        } else {
            die($podcast_data_table['error']);
        }
    } else {
        die("Error fetching RSS feed.");
    }
}

// Search and add new podcasts
$new_podcasts = [];
if (isset($_GET['search'])) {
    $search_query = $_GET['search'];

    $stmt = $conn->prepare("SELECT uuid, name, itunesId, description, imageUrl, rssUrl FROM taddypodcasts WHERE name = ?");
    $stmt->bind_param("s", $search_query);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $new_podcasts = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $api_url = 'https://api.taddy.org/graphql';
        $headers = [
            'Content-Type: application/json',
            "X-USER-ID: $taddy_user",
            "X-API-KEY: $taddy_api_key",
        ];
        $query = [
            'query' => '{
                getPodcastSeries(name: "' . $search_query . '") {
                    uuid
                    name
                    itunesId
                    description
                    imageUrl
                    rssUrl
                    itunesInfo {
                        uuid
                        baseArtworkUrlOf(size: 640)
                    }
                }
            }'
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response !== false) {
            $response_data = json_decode($response, true);
            if (isset($response_data['data']['getPodcastSeries'])) {
                $new_podcasts = $response_data['data']['getPodcastSeries'];
                if (isset($new_podcasts['uuid'])) {
                    $new_podcasts = [$new_podcasts];
                }
            } else {
                die("Error in Taddy API response.");
            }
        } else {
            die("Error searching for podcasts.");
        }
    }
    $stmt->close();
}

// Insert new podcast if not already in database
if (isset($_GET['rssUrl']) && !empty($_GET['rssUrl'])) {
    $rssUrl = $_GET['rssUrl'];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM taddypodcasts WHERE rssUrl = ?");
    $stmt->bind_param("s", $rssUrl);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count == 0) {
        $rss_content = fetch_rss_feed($rssUrl);
        if ($rss_content !== false) {
            $podcast_data = parse_rss_feed($rss_content);
            if (!isset($podcast_data['error'])) {
                $podcast_info = $podcast_data['podcast_info'];
                $uuid = ''; // Default empty value for uuid
                $itunesId = ''; // Default empty value for itunesId

                // Fetch uuid and itunesId from $new_podcasts if available
                foreach ($new_podcasts as $podcast) {
                    if ($podcast['rssUrl'] == $rssUrl) {
                        $uuid = $podcast['uuid'] ?? '';
                        $itunesId = $podcast['itunesId'] ?? '';
                        break;
                    }
                }

                $stmt = $conn->prepare("INSERT INTO taddypodcasts (name, itunesId, description, imageUrl, rssUrl) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $podcast_info['title'], $itunesId, $podcast_info['description'], $podcast_info['imageUrl'], $rssUrl);
                $stmt->execute();
                $stmt->close();
            } else {
                die($podcast_data['error']);
            }
        } else {
            die("Error fetching RSS feed.");
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podcast Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://unpkg.com/wavesurfer.js"></script>

<style>

.gridthumbs-img {
    max-width: 100px !important;
}

.thumbnail-img {
           max-width: 200px !important;
       }
</style>

</head>
<body>

  <div class="container-fluid">

        <?php include "nav.php"; ?>

<div class="container mt-5">


    <h1>Podcast Directory</h1>

        <!-- Taddy Podcast Search Form -->

    <form method="get">
        <div class="input-group mb-3">
            <input type="text" class="form-control" placeholder="Search for podcasts" name="search" aria-label="Search for podcasts">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
    </form>


  <!-- Podcast Grid -->

  <!-- Podcast Artworks Grid -->
     <div class="row">
         <?php
         $count = 0;
         foreach ($podcasts as $podcast):
             ?>
             <div class="col-1 mb-2 thumbnail-row">
                 <a href="?rssUrl=<?php echo urlencode($podcast['rssUrl']); ?>">
                     <img src="<?php echo htmlspecialchars($podcast['imageUrl']); ?>" alt="Podcast Artwork" class="gridthumbs-img">
                 </a>
             </div>
             <?php
             $count++;
             if ($count % 12 == 0) {
                 echo '</div><div class="row mb-1 thumbnail-row">';
             }
         endforeach;
         ?>
     </div>





    <!-- Search Results -->
    <?php if (!empty($new_podcasts)): ?>
                    <h2>Search Results</h2>
                    <div class="row mt-5">
                        <?php foreach ($new_podcasts as $podcast): ?>
                            <div class="col-md-2">
                                <div class="card mb-4">
                                    <img src="<?php echo htmlspecialchars($podcast['imageUrl']); ?>" class="card-img-top thumbnail-img" alt="Podcast Artwork">
                                </div>
                            </div>
                            <div class="col-md-10">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo htmlspecialchars($podcast['name']); ?></h5>
                                    <p class="card-text overflow-auto bg-light" style="max-height: 114px;"><?php echo $podcast['description']; ?></p>
                                    <a href="?rssUrl=<?php echo urlencode($podcast['rssUrl']); ?>" class="btn btn-primary">Load Podcast</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>


    <!-- Series Banner -->
                <?php if (isset($_GET['rssUrl'])): ?>
                    <div class="row mt-5">
                        <div class="col-md-2">
                            <img src="<?php echo htmlspecialchars($podcast_info['imageUrl']); ?>" alt="Podcast Artwork" class="img-fluid thumbnail-img">
                        </div>
                        <div class="col-md-10">
                            <h1><?php echo htmlspecialchars($podcast_info['title']); ?></h1>
                            <p class="card-text overflow-auto bg-light" style="max-height: 140px !important;"><?php echo htmlspecialchars($podcast_info['description']); ?></p>
                        </div>
                    </div>


        <!--Episodes Table -->

                    <div id="episodes-table" class="row mt-5">
                        <h2 class="mt-5">Latest Episodes</h2>
                        <div class="table-wrapper">
                            <table class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th width="20%">Episode Title</th>
                                        <th width="40%">Description</th>
                                        <th width="20%">Published Date</th>
                                        <th width="20%">Play</th>

                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($latest_episodes_table as $episode): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($episode['title']); ?></td>
                                            <td>
                                                <div class="overflow-auto bg-light" style="max-height: 50px;">
                                                    <?php echo $episode['description']; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($episode['pub_date']); ?></td>
                                            <td>
                                                <?php if (!empty($episode['audio_url'])): ?>
                                                    <audio controls>
                                                        <source src="<?php echo htmlspecialchars($episode['audio_url']); ?>" type="audio/mpeg">
                                                        Your browser does not support the audio element.
                                                    </audio>
                                                <?php endif; ?>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>
            </div>
        </div>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Wavesurfer
        var wavesurfer = WaveSurfer.create({
            container: '#waveform',
            waveColor: 'violet',
            progressColor: 'purple'
        });

        // Load the audio file
        //wavesurfer.load('<?php echo $episode['audio_url']; ?>');
        wavesurfer.load('<?php echo htmlspecialchars($episode['audio_url']); ?>');


        // Optional: Add play/pause control
        var playButton = document.createElement('button');
        playButton.innerHTML = 'Play/Pause';
        playButton.addEventListener('click', function() {
            wavesurfer.playPause();
        });

        document.body.appendChild(playButton);
    });
</script>




</body>
</html>
