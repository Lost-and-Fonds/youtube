# Dependency decisions

YouTube uses PHP JSON/XML/URL facilities and direct brokered REST calls. A Google API client is unnecessary for the small batched endpoint surface; a yt-dlp PHP wrapper would only duplicate the generic helper capability. yt-dlp and ffmpeg are pinned release inputs embedded in the plugin artifact.
