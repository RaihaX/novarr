<html>
    <head>
        <style>
            table {
                border-collapse: collapse;
                width: 100%;
            }

            th {
                border-bottom: 1pt solid black;
            }

            th.left {
                text-align: left;
            }

            th.right {
                text-align: right;
            }

            td {
                border-bottom: 1pt solid black;
            }

            td.left {
                text-align: left;
            }

            td.right {
                text-align: right;
            }
        </style>
    </head>
    <body>
        <table>
            <thead>
            <tr>
                <th class="left">Novel</th>
                <th class="right">Chapter</th>
                <th class="right">Book</th>
                <th class="right">Progress</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($data as $item)
                <tr bgcolor="{{ $item["progress"] > 90 ? "#f7626a" : "" }}">
                    <td class="left">{{ $item["novel"] }}</td>
                    <td class="right">{{ $item["chapter"] }}</td>
                    <td class="right">{{ $item["book"] }}</td>
                    <td class="right">{{ $item["progress"] }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </body>
</html>