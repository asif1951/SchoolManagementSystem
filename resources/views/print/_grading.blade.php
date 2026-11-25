<div class="gradehead">Grading system</div>
<table class="tab grading">
    <tbody>
        <tr><th class="left">Marks</th>@foreach ($grading as $g) <td>{{ $g['range'] }}</td> @endforeach</tr>
        <tr><th class="left">Letter grade</th>@foreach ($grading as $g) <td><b>{{ $g['grade'] }}</b></td> @endforeach</tr>
        <tr><th class="left">Grade point</th>@foreach ($grading as $g) <td>{{ number_format($g['point'], 2) }}</td> @endforeach</tr>
    </tbody>
</table>
